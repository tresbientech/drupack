package state

import (
	"cmp"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
)

const (
	listenerName   = "listener"
	bundleName     = "ca-bundle.pem"
	caddyfileName  = "Caddyfile"
	publisherName  = "mercure_publisher_secret"
	subscriberName = "mercure_subscriber_secret"
	// UnrecordedScheme is the scheme of a record that names none.
	UnrecordedScheme = "http"
)

// Listener is the address the last start served, as the Site data's `listener` record keeps it.
type Listener struct {
	// Listen is IP:PORT.
	Listen string `json:"listen"`
	Host   string `json:"host"`
	// FilesDir is "" when the start named no files directory.
	FilesDir string `json:"files-dir"`
	Scheme   string `json:"scheme"`
	// StaticListen is "" for a site without a static listener.
	StaticListen string `json:"static-listen"`
}

// UnreadableError is a record that holds no JSON object.
type UnreadableError struct {
	Path string
}

func (e *UnreadableError) Error() string {
	return "Cannot read the recorded listener: " + e.Path
}

// Listener reads the record. A Site data no start has served holds none, and
// ok is false. A release up to 1.0.0-beta8 recorded no scheme and served HTTP.
func (d Dir) Listener() (record Listener, ok bool, err error) {
	path := filepath.Join(d.path, listenerName)
	content, err := readShared(path)
	if errors.Is(err, fs.ErrNotExist) {
		return Listener{}, false, nil
	}
	if err != nil {
		return Listener{}, false, err
	}
	if err := json.Unmarshal(content, &record); err != nil {
		return Listener{}, false, &UnreadableError{Path: path}
	}
	if record.Listen == "" {
		return Listener{}, false, errors.New("Recorded listener has no listen address")
	}
	if record.Host == "" {
		return Listener{}, false, errors.New("Recorded listener has no host")
	}
	record.Scheme = cmp.Or(record.Scheme, UnrecordedScheme)
	return record, true, nil
}

// Bundle is the CA bundle the last HTTPS start wrote into d. ok is false after
// an HTTP start, which leaves none.
func (d Dir) Bundle() (path string, ok bool) {
	path = filepath.Join(d.path, bundleName)
	info, err := os.Stat(path)
	return path, err == nil && info.Mode().IsRegular()
}

// MercureSecrets reads the hub's two secrets. ok is false until a start has
// created both.
func (d Dir) MercureSecrets() (publisher, subscriber string, ok bool, err error) {
	var values [2]string
	for i, name := range []string{publisherName, subscriberName} {
		file, err := OpenFile(filepath.Join(d.path, name), os.O_RDONLY, 0)
		if errors.Is(err, fs.ErrNotExist) {
			return "", "", false, nil
		}
		if err != nil {
			return "", "", false, err
		}
		content, err := io.ReadAll(file)
		file.Close()
		if err != nil {
			return "", "", false, err
		}
		values[i] = string(content)
	}
	return values[0], values[1], true, nil
}

// StopRecordPath is where the server writes its stop record, which Stop reads.
func (d Dir) StopRecordPath() string {
	return filepath.Join(d.path, stopRecordName)
}

// ClearStopRecord removes an earlier server's record, which names a port and
// token nobody listens on. A missing record reads as a start still preparing.
func (d Dir) ClearStopRecord() error {
	err := os.Remove(d.StopRecordPath())
	if errors.Is(err, fs.ErrNotExist) {
		return nil
	}
	return err
}

// WriteBundle replaces the CA bundle whole, since a running server's cron
// reads it.
func (d Dir) WriteBundle(content []byte) error {
	return d.writeWhole(bundleName, content)
}

// writeWhole replaces a file whole, so a reader sees the old content or the new.
func (d Dir) writeWhole(name string, content []byte) error {
	path := filepath.Join(d.path, name)
	if err := WriteFile(path, content); err != nil {
		return fmt.Errorf("Cannot write %s: %w", path, err)
	}
	return nil
}

// WriteFile replaces path whole with content, through an owner-only file
// staged beside it.
func WriteFile(path string, content []byte) error {
	staged, err := os.CreateTemp(filepath.Dir(path), filepath.Base(path)+".")
	if err != nil {
		return err
	}
	if _, err := staged.Write(content); err != nil {
		staged.Close()
		os.Remove(staged.Name())
		return err
	}
	return ReplaceFile(staged, path)
}

// ReplaceFile syncs and closes staged, then renames it over path. A reader
// sees the old file or the whole new one, after a power loss too. A failure
// removes staged.
func ReplaceFile(staged *os.File, path string) error {
	err := staged.Sync()
	if closed := staged.Close(); err == nil {
		err = closed
	}
	if err == nil {
		err = replaceFile(staged.Name(), path)
	}
	if err != nil {
		os.Remove(staged.Name())
	}
	return err
}

// WriteCaddyfile replaces the start's rendered Caddyfile and returns its path.
// The file holds the Mercure secrets, so it is owner-only like the directory.
func (d Dir) WriteCaddyfile(content []byte) (string, error) {
	return filepath.Join(d.path, caddyfileName), d.writeWhole(caddyfileName, content)
}

// RemoveBundle deletes the bundle after an HTTP start, so Drush stops trusting
// a root the site no longer serves.
func (d Dir) RemoveBundle() error {
	err := os.Remove(filepath.Join(d.path, bundleName))
	if errors.Is(err, fs.ErrNotExist) {
		return nil
	}
	return err
}

// CreateMercureSecrets reads the hub's two secrets, creating either that is
// missing with 32 random bytes in hex. Exclusive creation and the read both
// refuse a link at the path.
func (d Dir) CreateMercureSecrets() (publisher, subscriber string, err error) {
	var values [2]string
	for i, name := range []string{publisherName, subscriberName} {
		path := filepath.Join(d.path, name)
		file, err := os.OpenFile(path, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0o600)
		if err == nil {
			secret := make([]byte, 32)
			rand.Read(secret)
			_, err = file.WriteString(hex.EncodeToString(secret))
			if closeErr := file.Close(); err == nil {
				err = closeErr
			}
			if err != nil {
				return "", "", fmt.Errorf("Cannot write the Mercure secret: %s: %w", path, err)
			}
		}
		file, err = OpenFile(path, os.O_RDONLY, 0)
		if err != nil {
			return "", "", fmt.Errorf("Cannot create the Mercure secret: %s: %w", path, err)
		}
		content, err := io.ReadAll(file)
		file.Close()
		if err != nil {
			return "", "", err
		}
		values[i] = string(content)
	}
	return values[0], values[1], nil
}

// WriteListener records the addresses a folder's start serves, which drush and
// export read back. It replaces the record whole, in Site data's format.
func (d Dir) WriteListener(record Listener) error {
	text := func(value string) *string {
		if value == "" {
			return nil
		}
		return &value
	}
	content, _ := json.Marshal(struct {
		Listen       string  `json:"listen"`
		Host         string  `json:"host"`
		FilesDir     *string `json:"files-dir"`
		Scheme       string  `json:"scheme"`
		StaticListen *string `json:"static-listen"`
	}{record.Listen, record.Host, text(record.FilesDir), record.Scheme, text(record.StaticListen)})
	return d.writeWhole(listenerName, content)
}
