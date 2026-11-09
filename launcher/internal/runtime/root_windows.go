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

// x/sys/windows does not name FILE_DELETE_CHILD.
const fileDeleteChild = 0x40

// writeAccess holds every right that changes a directory, its ACL or its owner.
const writeAccess = windows.FILE_WRITE_DATA | windows.FILE_APPEND_DATA | windows.FILE_WRITE_EA |
	fileDeleteChild | windows.FILE_WRITE_ATTRIBUTES | windows.DELETE | windows.WRITE_DAC |
	windows.WRITE_OWNER | windows.GENERIC_WRITE | windows.GENERIC_ALL

// replaceAccess holds every right that lets an account remove or rename an
// entry, or grant itself that right.
const replaceAccess = windows.DELETE | windows.WRITE_DAC | windows.WRITE_OWNER | windows.GENERIC_ALL

// trustedInstaller owns the root of the system drive. x/sys/windows does not name its SID.
const trustedInstaller = "S-1-5-80-956008885-3418522649-1831038044-1853292631-2271478464"

// leaf reports the account outside trustedAccounts that owns dir, or that an ACL
// entry lets write to it or to what it will hold. Inherit-only entries count,
// since every entry created below takes them. Windows has no read bits to
// weigh, so readers changes nothing.
func leaf(dir string, readers bool) (*Exposed, error) {
	trusted, err := trustedAccounts()
	if err != nil {
		return nil, err
	}
	sd, owner, err := security(dir)
	if err != nil {
		return nil, err
	}
	if !trusts(trusted, owner) {
		return &Exposed{Folder: dir, Account: accountName(owner), Owns: true, Leaf: true}, nil
	}
	writer, err := otherWriter(sd, trusted, writeAccess, true)
	if err != nil || writer == "" {
		return nil, err
	}
	return &Exposed{Folder: dir, Account: writer, Leaf: true}, nil
}

// ancestor reports the account outside trustedAccounts and TrustedInstaller that
// owns dir, can delete child out of it, or can delete child itself. A right to
// create folders in dir, which every signed-in account holds on a drive's root,
// moves no existing entry.
func ancestor(dir, child string) (*Exposed, error) {
	trusted, err := trustedAccounts()
	if err != nil {
		return nil, err
	}
	installer, err := windows.StringToSid(trustedInstaller)
	if err != nil {
		return nil, err
	}
	trusted = append(trusted, installer)
	sd, owner, err := security(dir)
	if err != nil {
		return nil, err
	}
	if !trusts(trusted, owner) {
		return &Exposed{Folder: dir, Account: accountName(owner), Owns: true}, nil
	}
	if writer, err := otherWriter(sd, trusted, fileDeleteChild|windows.WRITE_DAC|windows.WRITE_OWNER|windows.GENERIC_ALL, false); err != nil || writer != "" {
		return exposedTo(dir, writer), err
	}
	childSD, _, err := security(child)
	if err != nil {
		return nil, err
	}
	writer, err := otherWriter(childSD, trusted, replaceAccess, false)
	return exposedTo(child, writer), err
}

func exposedTo(folder, writer string) *Exposed {
	if writer == "" {
		return nil
	}
	return &Exposed{Folder: folder, Account: writer}
}

// security reads the owner and DACL of the directory dir.
func security(dir string) (*windows.SECURITY_DESCRIPTOR, *windows.SID, error) {
	info, err := os.Lstat(dir)
	if err != nil {
		return nil, nil, err
	}
	if !info.IsDir() {
		return nil, nil, fmt.Errorf("not a directory: %s", dir)
	}
	sd, err := windows.GetNamedSecurityInfo(dir, windows.SE_FILE_OBJECT,
		windows.OWNER_SECURITY_INFORMATION|windows.DACL_SECURITY_INFORMATION)
	if err != nil {
		return nil, nil, err
	}
	owner, _, err := sd.Owner()
	return sd, owner, err
}

// otherWriter names the first account outside trusted that sd's DACL grants
// one of rights, or "" when there is none. inheritOnly counts entries that
// apply only to what the directory will hold. A missing or NULL DACL grants
// every account full access. An allow entry of a kind this does not read is a
// writer.
func otherWriter(sd *windows.SECURITY_DESCRIPTOR, trusted []*windows.SID, rights windows.ACCESS_MASK, inheritOnly bool) (string, error) {
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
		if !inheritOnly && ace.Header.AceFlags&windows.INHERIT_ONLY_ACE != 0 {
			continue
		}
		switch ace.Header.AceType {
		case windows.ACCESS_ALLOWED_ACE_TYPE:
			sid := (*windows.SID)(unsafe.Pointer(&ace.SidStart))
			if ace.Mask&rights != 0 && !trusts(trusted, sid) {
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
// OWNER, which is whoever creates each entry, and OWNER RIGHTS, which is the
// owner leaf has already checked. Python's mkdtemp grants
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
// so a directory under a parent other accounts can write still passes leaf. A
// parent it creates, and an existing dir, keep the ACL they inherit or hold.
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
// fallback. Its candidate still runs through claimRoot, since %TEMP% is not account-private
// like cacheRoots' own candidate.
func asciiRoot(root, name string, notice io.Writer) (string, error) {
	fallback, err := fallbackRoot(name)
	if err != nil {
		return "", err
	}
	mkdir := func(dir string) error {
		_, err := claimRoot(dir)
		return err
	}
	return resolveASCIIRoot(root, fallback, mkdir, shortPathName, notice)
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
