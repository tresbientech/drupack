//go:build unix

package runtime

import (
	"fmt"
	"io"
	"io/fs"
	"os"
	"os/user"
	"path/filepath"
	"slices"
	"strconv"
	"syscall"
)

// Exposure reports the account other than this user that owns the resolved
// absolute directory dir, or can write into it. readers counts read access too,
// for a directory whose contents should stay unseen. It reads dir alone, so a
// parent another account can write still lets that account rename dir. It
// returns nil when there is none, and an error when it cannot tell, which a
// caller treats as exposed.
func Exposure(dir string, readers bool) (*ExposedError, error) {
	info, stat, err := lstat(dir)
	if err != nil {
		return nil, err
	}
	if int(stat.Uid) != os.Getuid() {
		return &ExposedError{Folder: dir, Account: userName(stat.Uid), Owns: true}, nil
	}
	mask := fs.FileMode(0o022)
	if readers {
		mask = 0o077
	}
	if account := modeHolder(info.Mode().Perm()&mask, stat.Gid); account != "" {
		return &ExposedError{Folder: dir, Account: account}, nil
	}
	return nil, nil
}

func lstat(dir string) (fs.FileInfo, *syscall.Stat_t, error) {
	info, err := os.Lstat(dir) //nolint:gosec // G703: the operator sets this path.
	if err != nil {
		return nil, nil, err
	}
	if !info.IsDir() {
		return nil, nil, fmt.Errorf("not a directory: %s", dir)
	}
	return info, info.Sys().(*syscall.Stat_t), nil
}

// modeHolder names who the permission bits grant: other accounts before
// the group, since they are the wider set.
func modeHolder(bits fs.FileMode, gid uint32) string {
	switch {
	case bits&0o007 != 0:
		return "every account"
	case bits&0o070 != 0:
		if group, err := user.LookupGroupId(strconv.Itoa(int(gid))); err == nil {
			return "group " + group.Name
		}
		return fmt.Sprintf("group %d", gid)
	}
	return ""
}

func userName(uid uint32) string {
	if account, err := user.LookupId(strconv.Itoa(int(uid))); err == nil {
		return account.Username
	}
	return fmt.Sprintf("uid %d", uid)
}

// Owner names the account that owns path, following a link, and reports whether
// this user trusts it.
func Owner(path string) (account string, trusted bool, err error) {
	info, err := os.Stat(path)
	if err != nil {
		return "", false, err
	}
	groups, err := os.Getgroups()
	if err != nil {
		return "", false, err
	}
	stat := info.Sys().(*syscall.Stat_t)
	return userName(stat.Uid), trustedOwner(stat.Uid, stat.Gid, os.Getuid(), append(groups, os.Getgid())), nil
}

// trustedOwner accepts a path owned by me or root, or by one of my groups.
func trustedOwner(uid, gid uint32, me int, groups []int) bool {
	return int(uid) == me || uid == 0 || slices.Contains(groups, int(gid))
}

// Create makes dir and any missing parent, each with mode 0700 under the umask.
func Create(dir string) error {
	return os.MkdirAll(dir, rootMode) //nolint:gosec // G703: the operator sets this path.
}

// asciiRoot returns root unchanged: the ANSI code page problem is Windows-only.
func asciiRoot(root, name string, notice io.Writer) (string, error) {
	return root, nil
}

func cacheRoots(name string) []string {
	var roots []string
	if cache, err := os.UserCacheDir(); err == nil {
		roots = append(roots, filepath.Join(cache, name, "runtime"))
	}
	// The temporary directory is world-writable, so another local user could
	// pre-create a shared path and leave a runtime there for this one to run.
	// Naming it per uid keeps each user in their own directory.
	owned := fmt.Sprintf("%s-%d", name, os.Getuid())
	return append(roots, filepath.Join(os.TempDir(), owned, "runtime"))
}
