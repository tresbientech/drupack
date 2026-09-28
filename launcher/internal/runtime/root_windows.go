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

// fileDeleteChild lets its holder remove any entry of a directory, whatever
// that entry's own ACL says. x/sys/windows does not name it.
const fileDeleteChild = 0x40

// writeAccess holds every right that changes a directory, what it holds, its
// ACL or its owner.
const writeAccess = windows.FILE_WRITE_DATA | windows.FILE_APPEND_DATA | windows.FILE_WRITE_EA |
	fileDeleteChild | windows.FILE_WRITE_ATTRIBUTES | windows.DELETE | windows.WRITE_DAC |
	windows.WRITE_OWNER | windows.GENERIC_WRITE | windows.GENERIC_ALL

// privateRoot reports root when it is a directory a trusted account owns and
// no ACL entry lets another account write to it or to what it will hold. The
// launcher runs code it unpacked there, so another account's write there runs
// as this one. Inherit-only entries count, since every unpacked file takes them.
func privateRoot(root string) (string, error) {
	info, err := os.Lstat(root)
	if err != nil {
		return "", err
	}
	if !info.IsDir() {
		return "", fmt.Errorf("cache root is not a directory: %s", root)
	}
	trusted, err := trustedAccounts()
	if err != nil {
		return "", err
	}
	sd, err := windows.GetNamedSecurityInfo(root, windows.SE_FILE_OBJECT,
		windows.OWNER_SECURITY_INFORMATION|windows.DACL_SECURITY_INFORMATION)
	if err != nil {
		return "", err
	}
	owner, _, err := sd.Owner()
	if err != nil {
		return "", err
	}
	if !trusts(trusted, owner) {
		return "", fmt.Errorf("cache root is owned by %s, not the current user: %s", accountName(owner), root)
	}
	writer, err := otherWriter(sd, trusted)
	if err != nil {
		return "", err
	}
	if writer != "" {
		return "", fmt.Errorf("cache root lets %s write to it: %s. Remove it and start again, "+
			"or set DRUPACK_CACHE_DIR to a directory that does not exist yet", writer, root)
	}
	return root, nil
}

// otherWriter names the first account outside trusted that sd's DACL lets
// write, or "" when there is none. A missing or NULL DACL grants every account
// full access. An allow entry of a kind this does not read counts as a writer.
func otherWriter(sd *windows.SECURITY_DESCRIPTOR, trusted []*windows.SID) (string, error) {
	dacl, _, err := sd.DACL()
	if errors.Is(err, windows.ERROR_OBJECT_NOT_FOUND) || (err == nil && dacl == nil) {
		return "Everyone", nil
	}
	if err != nil {
		return "", err
	}
	for i := uint32(0); i < uint32(dacl.AceCount); i++ {
		var ace *windows.ACCESS_ALLOWED_ACE
		if err := windows.GetAce(dacl, i, &ace); err != nil {
			return "", err
		}
		switch ace.Header.AceType {
		case windows.ACCESS_ALLOWED_ACE_TYPE:
			sid := (*windows.SID)(unsafe.Pointer(&ace.SidStart))
			if ace.Mask&writeAccess != 0 && !trusts(trusted, sid) {
				return accountName(sid), nil
			}
		case windows.ACCESS_DENIED_ACE_TYPE, accessDeniedObjectACE, accessDeniedCallbackACE, accessDeniedCallbackObjectACE:
			// A deny entry only takes access away.
		default:
			return fmt.Sprintf("an ACL entry of type %d", ace.Header.AceType), nil
		}
	}
	return "", nil
}

// The deny entry types beside ACCESS_DENIED_ACE_TYPE, which x/sys/windows does not name.
const (
	accessDeniedObjectACE         = 6
	accessDeniedCallbackACE       = 10
	accessDeniedCallbackObjectACE = 12
)

// trustedAccounts lists who may own or write a cache root: the current user,
// SYSTEM and Administrators, which can already act as any account, CREATOR
// OWNER, which stands for whoever creates each entry, and OWNER RIGHTS, which
// stands for the owner privateRoot has already checked. Python's mkdtemp grants
// OWNER RIGHTS on the directory it makes.
func trustedAccounts() ([]*windows.SID, error) {
	current, err := windows.GetCurrentProcessToken().GetTokenUser()
	if err != nil {
		return nil, err
	}
	trusted := []*windows.SID{current.User.Sid}
	for _, known := range []windows.WELL_KNOWN_SID_TYPE{
		windows.WinLocalSystemSid, windows.WinBuiltinAdministratorsSid, windows.WinCreatorOwnerSid,
		windows.WinCreatorOwnerRightsSid,
	} {
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

// accountName names sid as DOMAIN\name, as name alone for an account with no
// domain, or as its string form when no account resolves.
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

// makeRoot creates dir and any missing parent. When this call creates dir, dir
// gets a protected DACL granting the current user, SYSTEM and Administrators
// alone, so a root under a parent other accounts can write, a data drive's
// root for one, still passes privateRoot. An existing dir keeps its ACL.
func makeRoot(dir string) error {
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

// asciiRoot resolves root to the ASCII path PHP startup needs: phase 1 found
// PHP resolves its PHPRC-derived configuration path through the ANSI code
// page, so an account name it cannot represent breaks extension loading. The
// temporary directory is the fallback rung, since it sits under the same
// per-account profile and carries the same name; its candidate still runs
// through privateRoot like every other one, since %TEMP% is not the fixed,
// account-private location cacheRoots' own candidate is.
func asciiRoot(root, name string, notice io.Writer) (string, error) {
	fallback, err := fallbackRoot(name)
	if err != nil {
		return "", err
	}
	mkdir := func(dir string) error {
		if err := makeRoot(dir); err != nil {
			return err
		}
		_, err := privateRoot(dir)
		return err
	}
	return resolveASCIIRoot(root, fallback, mkdir, shortPathName, notice)
}

// fallbackRoot names asciiRoot's temp rung. %TMP% and %TEMP% are policy- and
// reader-writable, unlike cacheRoots' own candidate under the profile, so a
// fixed name here would let another account's start collide with this one's;
// namespacing it by SID, the way unix namespaces its own temp candidate by
// uid, keeps every account in its own directory.
func fallbackRoot(name string) (string, error) {
	current, err := user.Current()
	if err != nil {
		return "", err
	}
	return filepath.Join(os.TempDir(), name+"-"+current.Uid, "runtime"), nil
}

// shortPathName wraps GetShortPathNameW, the Windows API that names an
// existing path's 8.3 alias, the one Windows-only call resolveASCIIRoot
// needs, kept behind this thin function so the rung logic itself is
// testable on any host.
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
