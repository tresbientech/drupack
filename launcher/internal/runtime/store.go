package runtime

import (
	"fmt"
	"io"
	"os"
	"path/filepath"
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
}

func (c Cache) runtimes() store {
	return store{dir: c.root, noun: "runtime", hold: c.hold}
}

func (c Cache) nodes() store {
	return store{dir: NodeRoot(c.root), noun: "Node", hold: c.hold}
}

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
	if entry, ok, err := s.holdReady(sp); err != nil || ok {
		return entry, err
	}

	unlock, err := lockRoot(s.dir)
	if err != nil {
		return "", fmt.Errorf("could not lock the %s cache %s: %w", s.noun, s.dir, err)
	}
	defer unlock()

	// Another process may have finished staging while this one waited on the lock.
	if entry, ok, err := s.holdReady(sp); err != nil || ok {
		return entry, err
	}
	fmt.Fprintln(notice, sp.notice)
	entry := s.target(sp.name)
	aside := filepath.Join(s.dir, stagingPrefix+"removing-"+filepath.Base(entry))
	if err := swap(entry, aside, sp.marker, sp.contents, sp.fill); err != nil {
		return "", err
	}
	if err := s.hold(entry); err != nil {
		return "", err
	}
	return entry, nil
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
