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

const appDirName = "app"

// completeName is the last file created, so a directory without it was interrupted.
const completeName = ".complete"

// usedName's modification time dates the last start that ran the directory.
const usedName = ".used"

const unusedFor = 30 * 24 * time.Hour

// entryName uses a short form to keep vendor paths clear of the Windows path limit.
func entryName(checksum string) string {
	return "r" + checksum[:12]
}

func AppRoot(root string) string {
	return filepath.Join(root, appDirName)
}

// PrepareApp unpacks payload for the release checksum names the first time it is seen.
// A directory carrying the completion marker is used as it stands.
func (c Cache) PrepareApp(checksum string, payload []byte, notice io.Writer) (string, error) {
	if !SingleElement(checksum) || len(checksum) < 12 {
		return "", fmt.Errorf("application checksum is not a single path element: %q", checksum)
	}
	return c.applications().prepare(spec{
		name:   entryName(checksum),
		notice: "Unpacking the application. This happens once for each release.",
		ready:  complete,
		fill:   func(staging string) error { return extractApp(staging, payload, notice) },
		marker: completeName,
	}, notice)
}

// markUsed writes the file rather than touching it, since the marker may not exist yet.
func markUsed(entry string) error {
	return os.WriteFile(filepath.Join(entry, usedName), nil, 0600)
}

// sweepApps removes application directories unused for unusedFor, and the staging
// directories interrupted starts abandoned. It runs under the application cache lock, so no
// live staging directory exists here. A directory with no marker gets one, not a removal.
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
		// A server started more than unusedFor ago refreshes no timestamp, so the
		// marker alone would date a directory a site still serves from.
		if now.Sub(info.ModTime()) > unusedFor {
			retire(path, entryInUse)
		}
	}
}

// directorySize adds up the file sizes under path. A file that disappears during the walk counts nothing.
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

func complete(entry string) bool {
	_, err := os.Stat(filepath.Join(entry, completeName))
	return err == nil
}

// extractApp unpacks payload, a zstd-compressed tar stream, into destination. Archive
// paths are checked, not trusted: a path outside destination is refused whatever produced it.
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
