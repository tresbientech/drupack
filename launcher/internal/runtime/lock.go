package runtime

import (
	"fmt"
	"io"
	"path/filepath"
	"time"
)

// lockPollInterval paces the wait for a lock another process holds.
const lockPollInterval = 100 * time.Millisecond

// A wait for the lock says why after lockNoticeAfter and gives up after
// lockTimeout. An unpack runs under the lock, so a start that waits less is
// behind a working one. Tests shorten both.
var (
	lockNoticeAfter = 5 * time.Second
	lockTimeout     = 10 * time.Minute
)

// lockRoot serializes staging across every process sharing root, and returns
// the release. The system releases a dead holder's lock, so only a live one
// makes a start wait.
func lockRoot(root string, notice io.Writer) (func(), error) {
	started, told := time.Now(), false
	for {
		release, busy, err := tryLock(filepath.Join(root, lockName))
		waited := time.Since(started)
		switch {
		case err != nil:
			return nil, err
		case !busy:
			return release, nil
		case waited > lockTimeout:
			return nil, fmt.Errorf("another process held it for %s", lockTimeout)
		case waited > lockNoticeAfter && !told:
			fmt.Fprintf(notice, "Waiting for the lock on %s, which another process holds.\n", root)
			told = true
		}
		time.Sleep(lockPollInterval)
	}
}
