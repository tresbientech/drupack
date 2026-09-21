package runtime

import (
	"archive/tar"
	"bytes"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/klauspost/compress/zstd"
)

// appDirName holds every unpacked application, one directory per release.
const appDirName = "app"

// completeName marks an application directory as fully written. It is the last
// file created, so a directory without it was interrupted and unpacks again.
const completeName = ".complete"

// usedName records the last start that ran an application directory. The sweep
// reads its modification time.
const usedName = ".used"

// unusedFor is how long an application directory survives with no start using it.
const unusedFor = 30 * 24 * time.Hour

// entryName names the directory holding one release's application. The short
// form keeps the vendor paths inside the tree well clear of the Windows path
// limit.
func entryName(checksum string) string {
	return "r" + checksum[:12]
}

// AppRoot returns the directory holding unpacked applications under root.
func AppRoot(root string) string {
	return filepath.Join(root, appDirName)
}

// PrepareApp returns the directory holding the application that checksum
// names, unpacking payload there the first time this release is seen. A
// directory carrying the completion marker is used as it stands. Unpacking
// happens under the root lock, into a staging directory that moves into place
// once the marker is written, so a start interrupted halfway leaves nothing a
// later start mistakes for a finished copy.
func PrepareApp(root, checksum string, payload []byte, notice io.Writer) (string, error) {
	if !SingleElement(checksum) || len(checksum) < 12 {
		return "", fmt.Errorf("application checksum is not a single path element: %q", checksum)
	}
	appRoot := AppRoot(root)
	name := entryName(checksum)
	entry := filepath.Join(appRoot, name)
	if err := os.MkdirAll(appRoot, rootMode); err != nil {
		return "", err
	}

	unlock, err := lockRoot(appRoot)
	if err != nil {
		return "", fmt.Errorf("could not lock the application cache %s: %w", appRoot, err)
	}
	defer unlock()

	// Another process may have unpacked this release while this one waited on the lock.
	if !complete(entry) {
		fmt.Fprintf(notice, "Unpacking the Drupack application. This happens once for each release.\n")
		if err := unpackApp(appRoot, entry, name, payload, notice); err != nil {
			return "", err
		}
	}
	if err := markUsed(entry); err != nil {
		return "", err
	}
	sweepApps(appRoot, name, time.Now())
	return entry, nil
}

// unpackApp writes the application into a staging directory and moves it to
// entry once the completion marker is written, so a start interrupted halfway
// leaves nothing a later start mistakes for a finished copy.
func unpackApp(appRoot, entry, name string, payload []byte, notice io.Writer) error {
	staging := filepath.Join(appRoot, stagingPrefix+name)
	if err := removeTree(staging); err != nil {
		return err
	}
	// The archive names every directory it holds, so the staging directory is
	// the one the extraction never creates for itself.
	if err := os.MkdirAll(staging, 0700); err != nil {
		return err
	}
	if err := extractApp(staging, payload, notice); err != nil {
		removeTree(staging)
		return err
	}
	if err := os.WriteFile(filepath.Join(staging, completeName), nil, 0600); err != nil {
		removeTree(staging)
		return err
	}
	if err := removeTree(entry); err != nil {
		return err
	}
	if err := os.Rename(staging, entry); err != nil {
		removeTree(staging)
		return err
	}
	return nil
}

// markUsed dates the entry this start runs. Writing the file rather than
// touching it keeps one call for a marker that may not exist yet.
func markUsed(entry string) error {
	return os.WriteFile(filepath.Join(entry, usedName), nil, 0600)
}

// sweepApps removes the application directories no start has used for
// unusedFor, and the staging directories interrupted starts abandoned. It runs
// under the application cache lock, so no live staging directory exists here.
// A directory carrying no marker gets one, which dates a release unpacked
// before this release wrote markers instead of removing it.
func sweepApps(appRoot, keep string, now time.Time) {
	entries, err := os.ReadDir(appRoot)
	if err != nil {
		return
	}
	for _, candidate := range entries {
		name := candidate.Name()
		if name == keep || !candidate.IsDir() {
			continue
		}
		path := filepath.Join(appRoot, name)
		if strings.HasPrefix(name, stagingPrefix) {
			removeTree(path)
			continue
		}
		info, err := os.Stat(filepath.Join(path, usedName))
		if err != nil {
			markUsed(path)
			continue
		}
		if now.Sub(info.ModTime()) > unusedFor {
			removeTree(path)
		}
	}
}

// CleanApps reports every unpacked application under root, with the space it
// holds, and removes it unless dry names a listing alone. The release a reader
// runs next unpacks again on its first start.
func CleanApps(root string, dry bool, out io.Writer) error {
	appRoot := AppRoot(root)
	entries, err := os.ReadDir(appRoot)
	if err != nil {
		fmt.Fprintf(out, "No unpacked application in %s\n", appRoot)
		return nil
	}

	unlock, err := lockRoot(appRoot)
	if err != nil {
		return fmt.Errorf("could not lock the application cache %s: %w", appRoot, err)
	}
	defer unlock()

	var count int
	var freed int64
	for _, candidate := range entries {
		if !candidate.IsDir() {
			continue
		}
		path := filepath.Join(appRoot, candidate.Name())
		size := directorySize(path)
		fmt.Fprintf(out, "  %s  %d MB\n", candidate.Name(), size/megabyte)
		if !dry {
			if err := removeTree(path); err != nil {
				return err
			}
		}
		count++
		freed += size
	}
	if dry {
		fmt.Fprintf(out, "%d MB in %d unpacked %s. Run drupack clean to remove them.\n",
			freed/megabyte, count, applicationWord(count))
		return nil
	}
	fmt.Fprintf(out, "Removed %d unpacked %s, freeing %d MB.\n", count, applicationWord(count), freed/megabyte)
	return nil
}

func applicationWord(count int) string {
	if count == 1 {
		return "application"
	}
	return "applications"
}

// directorySize adds up the file sizes under path. A file that disappears
// while the walk runs counts nothing, which keeps a report from failing.
func directorySize(path string) int64 {
	var total int64
	filepath.WalkDir(path, func(_ string, entry fs.DirEntry, err error) error {
		if err != nil || entry.IsDir() {
			return nil
		}
		info, err := entry.Info()
		if err == nil {
			total += info.Size()
		}
		return nil
	})
	return total
}

// complete reports whether entry holds a fully unpacked application.
func complete(entry string) bool {
	_, err := os.Stat(filepath.Join(entry, completeName))
	return err == nil
}

// extractApp unpacks payload, a zstd-compressed tar stream, into destination.
// The archive arrives inside this executable, so its paths are checked rather
// than trusted: a crafted binary is a different problem, but a path outside
// destination is refused here whatever produced it.
func extractApp(destination string, payload []byte, notice io.Writer) error {
	reports := newProgress(int64(len(payload)), notice)
	decoder, err := zstd.NewReader(reports.reading(bytes.NewReader(payload)))
	if err != nil {
		return err
	}
	defer decoder.Close()
	defer reports.last()

	reader := tar.NewReader(decoder)
	for {
		header, err := reader.Next()
		if err == io.EOF {
			return nil
		}
		if err != nil {
			return err
		}
		name := filepath.Clean(filepath.FromSlash(header.Name))
		if name == "." {
			continue
		}
		if !SafePath(name) {
			return fmt.Errorf("application archive holds an unsafe path: %s", header.Name)
		}
		path := filepath.Join(destination, name)
		switch header.Typeflag {
		case tar.TypeDir:
			if err := os.MkdirAll(path, 0700); err != nil {
				return err
			}
		case tar.TypeReg:
			if err := writeAppFile(path, reader, header.FileInfo().Mode()); err != nil {
				return err
			}
		default:
			return fmt.Errorf("application archive holds an entry that is not a file or directory: %s", header.Name)
		}
	}
}

func writeAppFile(path string, contents io.Reader, mode os.FileMode) error {
	if err := os.MkdirAll(filepath.Dir(path), 0700); err != nil {
		return err
	}
	// The site writes into its own data directory, never here, so the unpacked
	// tree keeps the archive's own modes with write added for the owner alone.
	output, err := os.OpenFile(path, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, mode.Perm()|0600)
	if err != nil {
		return err
	}
	_, err = io.Copy(output, contents)
	closeErr := output.Close()
	if err == nil {
		err = closeErr
	}
	return err
}

// removeTree deletes path, restoring the modes on the way down. An application
// a site has served carries directories an installer made read-only, which the
// plain removal cannot unlink the contents of.
func removeTree(path string) error {
	filepath.WalkDir(path, func(name string, entry fs.DirEntry, err error) error {
		if err == nil && entry.IsDir() {
			os.Chmod(name, 0700)
		}
		return nil
	})
	return os.RemoveAll(path)
}
