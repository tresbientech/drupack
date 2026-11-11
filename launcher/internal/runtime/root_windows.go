//go:build windows

package runtime

import (
	"errors"
	"fmt"
	"io"
	"os"
	"os/user"
	"path/filepath"
	"syscall"
	"unsafe"

	"golang.org/x/sys/windows"
)

// Exposure reports the account outside trustedAccounts that owns the resolved
// absolute directory dir. It reads no ACL: Create gives a directory it makes a
// protected one, and an existing directory keeps the access its owner granted.
// Windows has no read bits to weigh, so readers changes nothing. It returns nil
// when there is none, and an error when it cannot tell, which a caller treats
// as exposed.
func Exposure(dir string, readers bool) (*Exposed, error) {
	info, err := os.Lstat(dir)
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
	return &Exposed{Folder: dir, Account: account, Owns: true}, nil
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
	if err := os.MkdirAll(filepath.Dir(dir), rootMode); err != nil {
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

// cacheRoots lists Root's candidates in trial order. Unix falls back to the
// world-writable temporary directory, which Windows has no counterpart for:
// its temporary directory lives in the same per-account profile as the cache
// directory, so a second candidate would name the same account's storage.
func cacheRoots(name string) []string {
	cache, err := os.UserCacheDir()
	if err != nil {
		return nil
	}
	return []string{filepath.Join(cache, name, "runtime")}
}

// asciiRoot resolves root to the ASCII path PHP startup needs, since PHP resolves its
// PHPRC-derived configuration path through the ANSI code page. The temporary directory is the
// fallback. Its candidate still runs through claimCandidate, since %TEMP% is not account-private
// like cacheRoots' own candidate.
func asciiRoot(root, name string, notice io.Writer) (string, error) {
	fallback, err := fallbackRoot(name)
	if err != nil {
		return "", err
	}
	return resolveASCIIRoot(root, fallback, claimCandidate, shortPathName, notice)
}

// fallbackRoot names asciiRoot's temp rung. %TMP% and %TEMP% are policy- and reader-writable,
// so the name carries the SID, as unix names its temp candidate by uid, keeping every account
// in its own directory.
func fallbackRoot(name string) (string, error) {
	current, err := user.Current()
	if err != nil {
		return "", err
	}
	return filepath.Join(os.TempDir(), name+"-"+current.Uid, "runtime"), nil
}

// shortPathName wraps GetShortPathNameW, which names an existing path's 8.3 alias, so the
// rung logic is testable on any host.
func shortPathName(path string) (string, error) {
	long, err := syscall.UTF16PtrFromString(path)
	if err != nil {
		return "", err
	}
	buf := make([]uint16, len(path)+1)
	n, err := syscall.GetShortPathName(long, &buf[0], uint32(len(buf)))
	if err != nil {
		return "", err
	}
	if int(n) > len(buf) {
		// The first buffer was too small; n names the size that fits, including
		// the trailing null GetShortPathName counts into its return value.
		buf = make([]uint16, n)
		if _, err := syscall.GetShortPathName(long, &buf[0], uint32(len(buf))); err != nil {
			return "", err
		}
	}
	return syscall.UTF16ToString(buf), nil
}
