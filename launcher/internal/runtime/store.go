package runtime

import (
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"time"
)

// Cache is one site's cache root, with the hold every start takes on the
// entries it runs from.
type Cache struct {
	root string
	hold func(entry string) error
}

// NewCache returns the cache under root. hold marks an entry in use for the
// rest of the process. Production passes HoldUsage. Tests pass a hold that
// keeps no handle, since a Windows hold blocks t.TempDir cleanup. A hold error
// stops the start: an unmarked entry can be removed under the run.
func NewCache(root string, hold func(entry string) error) Cache {
	return Cache{root: root, hold: hold}
}

// store is the directory holding one kind of cache entry. noun names the kind
// in messages.
type store struct {
	dir  string
	noun string
	hold func(entry string) error
	// retain runs under the lock on every start of a kind that dates and sweeps
	// its entries, with the entry the start runs. Nil for a kind that keeps
	// entries until activation or clean removes them.
	retain func(entry string) error
	// owns reports whether a directory in dir is an entry of this kind.
	owns  func(path string) bool
	inUse func(path string) bool
	// label prefixes an entry's name in clean's listing. one and many name the
	// kind in its totals.
	label, one, many string
	// active is true for a kind whose directory keeps an active file naming
	// the entry a start last activated.
	active bool
}

func (c Cache) runtimes() store {
	return store{dir: c.root, noun: "runtime", hold: c.hold, owns: ownedRuntime, inUse: entryInUse,
		one: "unpacked runtime", many: "unpacked runtimes", active: true}
}

func (c Cache) applications() store {
	dir := AppRoot(c.root)
	return store{dir: dir, noun: "application", hold: c.hold, retain: func(entry string) error {
		if err := markUsed(entry); err != nil {
			return err
		}
		sweepApps(dir, filepath.Base(entry), time.Now())
		return nil
	}, owns: anyDirectory, inUse: entryInUse,
		one: "unpacked application", many: "unpacked applications"}
}

func (c Cache) nodes() store {
	return store{dir: NodeRoot(c.root), noun: "Node", hold: c.hold, owns: anyDirectory, inUse: entryInUse,
		label: nodeDirName + "/", one: "unpacked Node release", many: "unpacked Node releases"}
}

// folders holds the engine's served folders' state, one entry each, which
// serve.php writes. A served folder's server holds the lease in its entry.
func (c Cache) folders() store {
	return store{dir: filepath.Join(c.root, folderDirName), noun: "folder", owns: anyDirectory, inUse: leaseHeld,
		label: folderDirName + "/", one: "folder entry", many: "folder entries"}
}

// anyDirectory owns every directory: the application, Node and folder
// directories hold nothing this program and serve.php did not write.
func anyDirectory(string) bool { return true }

// spec describes one entry a start needs: its name, how to tell it is whole,
// and how to write it.
type spec struct {
	name string
	// notice is the line printed before the entry is written.
	notice string
	ready  func(entry string) bool
	// fill writes every file of the entry but its marker into staging.
	fill     func(staging string) error
	marker   string
	contents []byte
}

// prepare returns the directory holding the entry sp describes, writing it the
// first time. It calls hold on the entry before the lock releases, so no
// removal takes the lock and finds the entry unmarked.
func (s store) prepare(sp spec, notice io.Writer) (string, error) {
	if err := os.MkdirAll(s.dir, rootMode); err != nil {
		return "", err
	}
	entry, ok, err := s.holdReady(sp)
	if err != nil || ok && s.retain == nil {
		return entry, err
	}

	unlock, err := lockRoot(s.dir)
	if err != nil {
		return "", fmt.Errorf("could not lock the %s cache %s: %w", s.noun, s.dir, err)
	}
	defer unlock()

	if !ok {
		if entry, err = s.write(sp, notice); err != nil {
			return "", err
		}
	}
	if s.retain != nil {
		if err := s.retain(entry); err != nil {
			return "", err
		}
	}
	return entry, nil
}

// write returns the entry sp describes, staging it unless another process
// finished staging while this one waited on the lock. The caller holds the lock.
func (s store) write(sp spec, notice io.Writer) (string, error) {
	if entry, ok, err := s.holdReady(sp); err != nil || ok {
		return entry, err
	}
	fmt.Fprintln(notice, sp.notice)
	entry := s.target(sp.name)
	if err := swap(entry, asideName(entry), sp.marker, sp.contents, sp.fill); err != nil {
		return "", err
	}
	return entry, s.hold(entry)
}

// holdReady calls hold on the entry readyEntry finds, then checks the entry is
// still whole. A removal renames an entry away before it deletes it, and the
// first look runs without the lock, so a removal that beat the hold leaves the
// entry missing here and the caller stages afresh.
func (s store) holdReady(sp spec) (string, bool, error) {
	entry, ok := s.readyEntry(sp)
	if !ok {
		return "", false, nil
	}
	if err := s.hold(entry); err != nil {
		// A removal that beat the hold is the case above, not a failure.
		if _, statErr := os.Stat(entry); statErr != nil {
			return "", false, nil
		}
		return "", false, err
	}
	return entry, sp.ready(entry), nil
}

// readyEntry reports the directory already holding the entry sp describes: the
// one named sp.name, or the one the directory's active file names, since a
// start that found the name held by a running site staged under a name of its own.
func (s store) readyEntry(sp spec) (string, bool) {
	if entry := filepath.Join(s.dir, sp.name); sp.ready(entry) {
		return entry, true
	}
	if !s.active {
		return "", false
	}
	name, err := activeKey(s.dir)
	if err != nil || name == sp.name {
		return "", false
	}
	if entry := filepath.Join(s.dir, name); sp.ready(entry) {
		return entry, true
	}
	return "", false
}

// target returns the path a new entry named name takes. An unready entry under
// that name is replaced, unless a site still runs from it: Windows refuses to
// replace a directory a site has files open in. The new entry then takes the
// first name.N no directory holds. The caller holds the lock.
func (s store) target(name string) string {
	path := filepath.Join(s.dir, name)
	if !entryInUse(path) {
		return path
	}
	for n := 1; ; n++ {
		candidate := fmt.Sprintf("%s.%d", path, n)
		if _, err := os.Lstat(candidate); err != nil {
			return candidate
		}
	}
}

// swap writes an entry at target. fill writes it into a staging directory
// beside target, marker is written last, and the result takes target's place.
// A directory missing its marker is never used, so an interrupted swap leaves
// nothing a later start mistakes for a whole entry. A directory already at
// target moves to aside first and is removed once the new entry is in place.
// The caller holds the lock over target's directory.
func swap(target, aside, marker string, contents []byte, fill func(staging string) error) error {
	staging := filepath.Join(filepath.Dir(target), stagingPrefix+filepath.Base(target))
	if err := removeTree(staging); err != nil {
		return err
	}
	if err := os.MkdirAll(staging, 0700); err != nil {
		return err
	}
	err := fill(staging)
	if err == nil {
		err = os.WriteFile(filepath.Join(staging, marker), contents, 0600)
	}
	if err == nil {
		err = replace(target, aside, staging)
	}
	if err != nil {
		removeTree(staging)
		return err
	}
	return removeTree(aside)
}

// replace moves staging to target, moving a directory already at target to aside.
func replace(target, aside, staging string) error {
	if _, err := os.Lstat(target); err == nil {
		if err := removeTree(aside); err != nil {
			return err
		}
		if err := os.Rename(target, aside); err != nil {
			return err
		}
	}
	return os.Rename(staging, target)
}

// retire removes the entry at path unless a process runs from it, and reports
// whether the entry left its path. A start holds an entry without the lock when
// it finds it ready, so a hold can land between the test and the removal. The
// entry therefore moves to a staging name first and is tested again there:
// Windows refuses the move while a handle is open inside, and the flock on unix
// follows the directory through the move. A holder that comes later finds the
// entry gone from its path.
func retire(path string, inUse func(string) bool) (bool, error) {
	if inUse(path) {
		return false, nil
	}
	doomed := asideName(path)
	if err := removeTree(doomed); err != nil {
		return false, err
	}
	if os.Rename(path, doomed) != nil {
		return false, nil
	}
	if inUse(doomed) {
		// A failed rename back leaves the entry whole under its staging name, and
		// the next removal tests it there before it removes anything.
		os.Rename(doomed, path)
		return false, nil
	}
	return true, removeTree(doomed)
}

// Clean lists every cache entry with the space it holds, and removes each one
// unless dry names a listing alone. An entry a running site uses is listed and
// kept. name is the executable's name, for the line that says how to remove
// what a dry run listed.
func (c Cache) Clean(name string, dry bool, out io.Writer) error {
	var listed int
	for _, s := range []store{c.runtimes(), c.applications(), c.nodes(), c.folders()} {
		n, err := s.clean(dry, out)
		if err != nil {
			return err
		}
		listed += n
	}
	switch {
	case listed == 0:
		fmt.Fprintf(out, "Nothing to clean in %s.\n", c.root)
	case dry:
		fmt.Fprintf(out, "Run %s clean to remove them.\n", name)
	}
	return nil
}

// clean lists the kind's entries and removes each one unless dry. It returns
// how many entries it listed, and prints nothing for a kind with none.
func (s store) clean(dry bool, out io.Writer) (int, error) {
	entries, err := os.ReadDir(s.dir)
	if errors.Is(err, fs.ErrNotExist) {
		return 0, nil
	}
	if err != nil {
		return 0, err
	}
	unlock, err := lockRoot(s.dir)
	if err != nil {
		return 0, fmt.Errorf("could not lock the %s cache %s: %w", s.noun, s.dir, err)
	}
	defer unlock()

	var count, held int
	var freed int64
	for _, candidate := range entries {
		path := filepath.Join(s.dir, candidate.Name())
		if !candidate.IsDir() || !s.owns(path) {
			continue
		}
		size := directorySize(path)
		kept := s.inUse(path)
		if !kept && !dry {
			removed, err := retire(path, s.inUse)
			if err != nil {
				return 0, err
			}
			kept = !removed
		}
		if kept {
			// A site serving from this entry reads its files on every request.
			fmt.Fprintf(out, "  %s%s  %d MB  in use by a running site, kept\n", s.label, candidate.Name(), size/megabyte)
			held++
			continue
		}
		fmt.Fprintf(out, "  %s%s  %d MB\n", s.label, candidate.Name(), size/megabyte)
		count++
		freed += size
	}
	if count+held == 0 {
		return 0, nil
	}
	if dry {
		fmt.Fprintf(out, "%d MB in %d %s.\n", freed/megabyte, count, s.words(count))
	} else {
		fmt.Fprintf(out, "Removed %d %s, freeing %d MB.\n", count, s.words(count), freed/megabyte)
	}
	if held > 0 {
		fmt.Fprintf(out, "Kept %d %s a running site still uses. Stop the site, then clean again.\n", held, s.words(held))
	}
	return count + held, nil
}

func (s store) words(count int) string {
	if count == 1 {
		return s.one
	}
	return s.many
}
