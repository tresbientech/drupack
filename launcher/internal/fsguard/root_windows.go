//go:build windows

package fsguard

import (
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"unsafe"

	"golang.org/x/sys/windows"
)

// Exposure reports the account outside trustedAccounts that owns the resolved
// absolute directory dir. It reads no ACL: Create gives a directory it makes a
// protected one, and an existing directory keeps the access its owner granted.
// Windows has no read bits to weigh, so readers changes nothing. It returns nil
// when there is none, and an error when it cannot tell, which a caller treats
// as exposed.
func Exposure(dir string, readers bool) (*ExposedError, error) {
	info, err := os.Lstat(dir) //nolint:gosec // G703: the operator sets this path.
	if err != nil {
		return nil, err
	}
	if !info.IsDir() {
		return nil, fmt.Errorf("not a directory: %s", dir)
	}
	account, trusted, err := Owner(dir)
	if err != nil || trusted {
		return nil, err
	}
	return &ExposedError{Folder: dir, Account: account, Owns: true}, nil
}

// Owner names the account that owns path, following a link, and reports whether
// it is one of trustedAccounts.
func Owner(path string) (account string, trusted bool, err error) {
	accounts, err := trustedAccounts()
	if err != nil {
		return "", false, err
	}
	sd, err := windows.GetNamedSecurityInfo(path, windows.SE_FILE_OBJECT, windows.OWNER_SECURITY_INFORMATION)
	if err != nil {
		return "", false, err
	}
	owner, _, err := sd.Owner()
	if err != nil {
		return "", false, err
	}
	return accountName(owner), trusts(accounts, owner), nil
}

// trustedAccounts lists who may own a folder a start runs code from: the current user,
// and SYSTEM and Administrators, which can already act as any account.
func trustedAccounts() ([]*windows.SID, error) {
	current, err := windows.GetCurrentProcessToken().GetTokenUser()
	if err != nil {
		return nil, err
	}
	trusted := []*windows.SID{current.User.Sid}
	for _, known := range []windows.WELL_KNOWN_SID_TYPE{windows.WinLocalSystemSid, windows.WinBuiltinAdministratorsSid} {
		sid, err := windows.CreateWellKnownSid(known)
		if err != nil {
			return nil, err
		}
		trusted = append(trusted, sid)
	}
	return trusted, nil
}

func trusts(trusted []*windows.SID, sid *windows.SID) bool {
	for _, account := range trusted {
		if account.Equals(sid) {
			return true
		}
	}
	return false
}

func accountName(sid *windows.SID) string {
	account, domain, _, err := sid.LookupAccount("")
	if err != nil {
		return sid.String()
	}
	if domain == "" {
		return account
	}
	return domain + `\` + account
}

// Create makes dir and any missing parent. When this call creates dir, dir gets
// a protected DACL granting the current user, SYSTEM and Administrators alone,
// whatever its parent would have it inherit. A parent it creates, and an
// existing dir, keep the ACL they inherit or hold.
func Create(dir string) error {
	if err := os.MkdirAll(filepath.Dir(dir), DirMode); err != nil { //nolint:gosec // G703: the operator sets this path.
		return err
	}
	current, err := windows.GetCurrentProcessToken().GetTokenUser()
	if err != nil {
		return err
	}
	user := current.User.Sid.String()
	sd, err := windows.SecurityDescriptorFromString(
		"O:" + user + "D:P(A;OICI;FA;;;" + user + ")(A;OICI;FA;;;SY)(A;OICI;FA;;;BA)")
	if err != nil {
		return err
	}
	path, err := windows.UTF16PtrFromString(dir)
	if err != nil {
		return err
	}
	attributes := windows.SecurityAttributes{SecurityDescriptor: sd}
	attributes.Length = uint32(unsafe.Sizeof(attributes))
	if err := windows.CreateDirectory(path, &attributes); err != nil && !errors.Is(err, windows.ERROR_ALREADY_EXISTS) {
		return &os.PathError{Op: "mkdir", Path: dir, Err: err}
	}
	return nil
}
