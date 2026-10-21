package runtime

import (
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"strings"
)

const siteAppName = "app"

// previousAppName holds the old application while an upgrade moves the next one in.
const previousAppName = ".previous-app"

// releaseName is the last file written.
const releaseName = ".release"

func SiteApp(data string) string {
	return filepath.Join(data, siteAppName)
}

func SiteAppCurrent(data, checksum string) bool {
	recorded, err := os.ReadFile(filepath.Join(SiteApp(data), releaseName))
	return err == nil && string(recorded) == checksum
}

// LaySiteApp makes data hold the application payload carries, with each writable
// directory present. An application already laid from checksum is used as it stands.
// Laying runs under a lock in data's runtime directory, into a staging directory that
// replaces the old application once its release marker is written. An upgrade copies in
// each entry of a writable directory the old application holds and this release does not ship.
func LaySiteApp(data, checksum string, payload []byte, writable []string, notice io.Writer) error {
	// The writable list arrives on the command line of a word anyone can type.
	for _, directory := range writable {
		if !SafePath(filepath.FromSlash(directory)) {
			return fmt.Errorf("writable directory is not a relative path inside the application: %q", directory)
		}
	}
	unlock, err := lockRoot(filepath.Join(data, "runtime"))
	if err != nil {
		return fmt.Errorf("could not lock the application in %s: %w", data, err)
	}
	defer unlock()
	entry := SiteApp(data)
	previous := filepath.Join(data, previousAppName)
	if SiteAppCurrent(data, checksum) {
		// An upgrade interrupted after its swap leaves the old application behind.
		return removeTree(previous)
	}
	fmt.Fprintf(notice, "Laying the application in %s. This happens once for each release.\n", data)
	// An upgrade interrupted between moving the old application aside and moving
	// the new one in leaves the old one in previous alone.
	old := entry
	if _, err := os.Stat(entry); err != nil {
		old = previous
	}
	return swap(entry, previous, releaseName, []byte(checksum), func(staging string) error {
		return layStaging(staging, old, payload, writable, notice)
	})
}

func layStaging(staging, old string, payload []byte, writable []string, notice io.Writer) error {
	if err := extractApp(staging, payload, notice); err != nil {
		return err
	}
	for _, directory := range writable {
		native := filepath.FromSlash(directory)
		if err := os.MkdirAll(filepath.Join(staging, native), 0700); err != nil {
			return err
		}
		if err := carryOver(filepath.Join(old, native), filepath.Join(staging, native)); err != nil {
			return err
		}
	}
	return nil
}

// carryOver copies each entry of from that to lacks. A missing from carries nothing.
func carryOver(from, to string) error {
	entries, err := os.ReadDir(from)
	if os.IsNotExist(err) {
		return nil
	}
	if err != nil {
		return err
	}
	for _, entry := range entries {
		destination := filepath.Join(to, entry.Name())
		if _, err := os.Lstat(destination); err == nil {
			continue
		}
		if err := copyTree(filepath.Join(from, entry.Name()), destination); err != nil {
			return err
		}
	}
	return nil
}

func copyTree(source, destination string) error {
	return filepath.WalkDir(source, func(path string, entry fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		relative, err := filepath.Rel(source, path)
		if err != nil {
			return err
		}
		target := filepath.Join(destination, relative)
		info, err := entry.Info()
		if err != nil {
			return err
		}
		switch {
		case entry.IsDir():
			return os.MkdirAll(target, info.Mode().Perm()|0700)
		case info.Mode()&fs.ModeSymlink != 0:
			link, err := os.Readlink(path)
			if err != nil {
				return err
			}
			return os.Symlink(link, target)
		case info.Mode().IsRegular():
			contents, err := os.Open(path)
			if err != nil {
				return err
			}
			defer contents.Close()
			return writeAppFile(target, contents, info.Mode())
		}
		return fmt.Errorf("cannot carry over %s, which is not a file, directory or link", path)
	})
}

// LayArguments reads what follows the lay-app word: the Site data directory
// then the writable directories, or --check and the Site data directory.
func LayArguments(arguments []string) (data string, writable []string, check bool, err error) {
	if len(arguments) == 2 && arguments[0] == "--check" {
		return arguments[1], nil, true, nil
	}
	if len(arguments) == 0 || strings.HasPrefix(arguments[0], "-") {
		return "", nil, false, fmt.Errorf("lay-app takes DATA DIRECTORY..., or --check DATA")
	}
	return arguments[0], arguments[1:], false, nil
}
