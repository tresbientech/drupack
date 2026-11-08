package state

import (
	"encoding/json"
	"errors"
	"io/fs"
	"os"
	"path/filepath"
)

const (
	listenerName     = "listener"
	bundleName       = "ca-bundle.pem"
	publisherName    = "mercure_publisher_secret"
	subscriberName   = "mercure_subscriber_secret"
	folderMercureURL = "mercure_url"
	// unrecordedScheme is the scheme of a record that names none.
	unrecordedScheme = "http"
)

// Listener is the address the last start served, as the Site data's `listener` record keeps it.
type Listener struct {
	// Listen is IP:PORT.
	Listen string
	Host   string
	// FilesDir is "" when the start named no files directory.
	FilesDir string
	Scheme   string
	// StaticListen is "" for a site without a static listener.
	StaticListen string
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
	var fields struct {
		Listen       *string `json:"listen"`
		Host         *string `json:"host"`
		FilesDir     *string `json:"files-dir"`
		Scheme       *string `json:"scheme"`
		StaticListen *string `json:"static-listen"`
	}
	if err := json.Unmarshal(content, &fields); err != nil {
		return Listener{}, false, &UnreadableError{Path: path}
	}
	if fields.Listen == nil {
		return Listener{}, false, errors.New("Recorded listener has no listen address")
	}
	if fields.Host == nil {
		return Listener{}, false, errors.New("Recorded listener has no host")
	}
	record = Listener{Listen: *fields.Listen, Host: *fields.Host, Scheme: unrecordedScheme}
	if fields.FilesDir != nil {
		record.FilesDir = *fields.FilesDir
	}
	if fields.Scheme != nil {
		record.Scheme = *fields.Scheme
	}
	if fields.StaticListen != nil {
		record.StaticListen = *fields.StaticListen
	}
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
		content, err := os.ReadFile(filepath.Join(d.path, name))
		if errors.Is(err, fs.ErrNotExist) {
			return "", "", false, nil
		}
		if err != nil {
			return "", "", false, err
		}
		values[i] = string(content)
	}
	return values[0], values[1], true, nil
}

// FolderMercureURL reads the hub address the last start of a folder served.
// ok is false when none recorded one.
func (d Dir) FolderMercureURL() (url string, ok bool, err error) {
	content, err := os.ReadFile(filepath.Join(d.path, folderMercureURL))
	if errors.Is(err, fs.ErrNotExist) {
		return "", false, nil
	}
	return string(content), err == nil, err
}
