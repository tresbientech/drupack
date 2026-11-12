//go:build unix

package state_test

import (
	"os"
	"path/filepath"
	"syscall"
	"testing"
	"time"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/state"
)

// PHP writes a record under an exclusive lock after it truncates the file, so a
// read that ignored the lock would see an empty record.
func TestListenerWaitsForAWriterHoldingTheRecord(t *testing.T) {
	dir := t.TempDir()
	writer, err := os.OpenFile(filepath.Join(dir, "listener"), os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o600)
	if err != nil {
		t.Fatal(err)
	}
	defer writer.Close()
	if err := syscall.Flock(int(writer.Fd()), syscall.LOCK_EX); err != nil {
		t.Fatal(err)
	}
	read := make(chan state.Listener)
	go func() {
		record, _, _ := state.At(dir).Listener()
		read <- record
	}()
	select {
	case <-read:
		t.Fatal("Listener() returned while a writer held the record")
	case <-time.After(300 * time.Millisecond):
	}
	if _, err := writer.WriteString(`{"listen":"127.0.0.1:1","host":"localhost","scheme":"http"}`); err != nil {
		t.Fatal(err)
	}
	if err := syscall.Flock(int(writer.Fd()), syscall.LOCK_UN); err != nil {
		t.Fatal(err)
	}
	select {
	case record := <-read:
		if record.Listen != "127.0.0.1:1" {
			t.Errorf("Listener() = %+v, want the whole record", record)
		}
	case <-time.After(5 * time.Second):
		t.Fatal("Listener() did not return once the writer let go")
	}
}
