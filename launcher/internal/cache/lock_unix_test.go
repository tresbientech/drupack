//go:build unix

package cache

import (
	"bytes"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

// waits shortens the lock's notice delay and deadline for one test.
func waits(t *testing.T, noticeAfter, timeout time.Duration) {
	t.Helper()
	savedNotice, savedTimeout := lockNoticeAfter, lockTimeout
	lockNoticeAfter, lockTimeout = noticeAfter, timeout
	t.Cleanup(func() { lockNoticeAfter, lockTimeout = savedNotice, savedTimeout })
}

// hold takes root's lock on an open file of its own, as another process would.
func hold(t *testing.T, root string) func() {
	t.Helper()
	release, busy, err := tryLock(filepath.Join(root, lockName))
	if err != nil || busy {
		t.Fatalf("tryLock on a free lock: busy %v, %v", busy, err)
	}
	return release
}

func TestLockRootSaysWhyItWaitsThenTakesTheLock(t *testing.T) {
	root := t.TempDir()
	waits(t, time.Millisecond, time.Minute)
	time.AfterFunc(3*lockPollInterval, hold(t, root))
	var notice bytes.Buffer
	release, err := lockRoot(root, &notice)
	if err != nil {
		t.Fatal(err)
	}
	defer release()
	if want := "Waiting for the lock on " + root + ", which another process holds.\n"; notice.String() != want {
		t.Errorf("notice = %q, want %q once", notice.String(), want)
	}
	if _, busy, _ := tryLock(filepath.Join(root, lockName)); !busy {
		t.Error("lockRoot returned without the lock")
	}
}

func TestLockRootGivesUpOnAHolderThatKeepsTheLock(t *testing.T) {
	root := t.TempDir()
	waits(t, time.Hour, 2*lockPollInterval)
	defer hold(t, root)()
	var notice bytes.Buffer
	_, err := lockRoot(root, &notice)
	if err == nil || !strings.Contains(err.Error(), "another process held it for 200ms") {
		t.Errorf("lockRoot = %v, want the wait it gave up after", err)
	}
	if notice.Len() != 0 {
		t.Errorf("notice = %q before its delay", notice.String())
	}
}
