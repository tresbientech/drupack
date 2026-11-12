package cache

import (
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"strings"
	"time"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/payload"
)

const appDirName = "app"

// DrushScript is where Drush lives under an application or a project.
var DrushScript = filepath.Join("vendor", "drush", "drush", "drush.php")

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

// PrepareApp unpacks archive for the release checksum names the first time it is seen.
// A directory carrying the completion marker is used as it stands.
func (c Cache) PrepareApp(checksum string, archive []byte, notice io.Writer) (string, error) {
	if !payload.SingleElement(checksum) || len(checksum) < 12 {
		return "", fmt.Errorf("application checksum is not a single path element: %q", checksum)
	}
	return c.applications().prepare(spec{
		name:   entryName(checksum),
		notice: "Unpacking the application. This happens once for each release.",
		ready:  complete,
		fill:   func(staging string) error { return payload.ExtractApp(staging, archive, notice) },
		marker: completeName,
	}, notice)
}

// markUsed writes the file rather than touching it, since the marker may not exist yet.
func markUsed(entry string) error {
	return os.WriteFile(filepath.Join(entry, usedName), nil, 0o600)
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
			_ = removeTree(path)
			continue
		}
		info, err := os.Stat(filepath.Join(path, usedName))
		if err != nil {
			_ = markUsed(path)
			continue
		}
		// A server started more than unusedFor ago refreshes no timestamp, so the
		// marker alone would date a directory a site still serves from.
		if now.Sub(info.ModTime()) > unusedFor {
			_, _ = retire(path, entryInUse)
		}
	}
}

// directorySize adds up the file sizes under path. A file that disappears during the walk counts nothing.
func directorySize(path string) int64 {
	var total int64
	_ = filepath.WalkDir(path, func(_ string, entry fs.DirEntry, err error) error {
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

// removeTree deletes path, restoring the modes on the way down. An application
// a site has served carries directories an installer made read-only, which the
// plain removal cannot unlink the contents of.
func removeTree(path string) error {
	_ = filepath.WalkDir(path, func(name string, entry fs.DirEntry, err error) error {
		if err == nil && entry.IsDir() {
			_ = os.Chmod(name, 0o700) //nolint:gosec // G122: the walk removes an entry of the launcher's own cache.
		}
		return nil
	})
	return os.RemoveAll(path)
}
