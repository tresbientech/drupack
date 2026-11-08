//go:build windows

package state_test

import (
	"path/filepath"
	"testing"
	"time"

	"golang.org/x/sys/windows"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/state"
)

// A reader that waits for the record's writer holds the file open. A start
// that rewrites the record replaces the file under it.
func TestListenerReaderLetsTheRecordBeRenamed(t *testing.T) {
	dir := t.TempDir()
	record := filepath.Join(dir, "listener")
	pointer, err := windows.UTF16PtrFromString(record)
	if err != nil {
		t.Fatal(err)
	}
	writer, err := windows.CreateFile(pointer, windows.GENERIC_READ|windows.GENERIC_WRITE,
		windows.FILE_SHARE_READ|windows.FILE_SHARE_WRITE|windows.FILE_SHARE_DELETE, nil, windows.CREATE_ALWAYS, windows.FILE_ATTRIBUTE_NORMAL, 0)
	if err != nil {
		t.Fatal(err)
	}
	defer windows.CloseHandle(writer)
	overlapped := new(windows.Overlapped)
	if err := windows.LockFileEx(writer, windows.LOCKFILE_EXCLUSIVE_LOCK|windows.LOCKFILE_FAIL_IMMEDIATELY, 0, 1, 0, overlapped); err != nil {
		t.Fatal(err)
	}
	read := make(chan error, 1)
	go func() {
		_, _, err := state.At(dir).Listener()
		read <- err
	}()
	select {
	case <-read:
		t.Fatal("Listener() returned while a writer held the record")
	case <-time.After(300 * time.Millisecond):
	}
	if err := state.At(dir).WriteListener(state.Listener{Listen: "127.0.0.1:1", Host: "localhost", Scheme: "http"}); err != nil {
		t.Errorf("replacing the record a reader waits on: %v", err)
	}
	if err := windows.UnlockFileEx(writer, 0, 1, 0, overlapped); err != nil {
		t.Fatal(err)
	}
	select {
	case <-read:
	case <-time.After(5 * time.Second):
		t.Fatal("Listener() did not return once the writer let go")
	}
}
