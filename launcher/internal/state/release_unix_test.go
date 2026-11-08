//go:build unix

package state

// Release closes the lease's descriptor, which ends the lock.
func (l *Lease) Release() error {
	return l.file.Close()
}
