//go:build windows

package state

import "golang.org/x/sys/windows"

// Release closes the lease's handle, which ends the lock. Windows removes no
// file a handle holds open, so a test releases before its directory goes.
func (l *Lease) Release() error {
	return windows.CloseHandle(l.handle)
}
