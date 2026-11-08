// Package state owns a state directory: Site data, or a folder's entry in the
// engine's cache. It holds the Serving lease and the stop record.
package state

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"net/http"
	"os"
	"path/filepath"
	"time"
)

// FoldersName is the cache directory holding one entry per folder the engine serves.
const FoldersName = "folders"

// LeaseName is the lock a server holds for as long as it runs. PHP's Serving
// class takes it with flock.
const LeaseName = "serving.lock"

// stopRecordName is the record a server writes once it answers.
const stopRecordName = "stop.json"

// pollInterval paces WaitFree.
const pollInterval = 200 * time.Millisecond

// Dir is one state directory.
type Dir struct {
	path string
}

func At(path string) Dir {
	return Dir{path: path}
}

// FolderEntry is the entry of project, a canonical path, under root. Windows
// spells one path in several casings, so fold lowercases ASCII letters first,
// as serve.php's strtolower does.
func FolderEntry(root, project string, fold bool) Dir {
	key := []byte(project)
	if fold {
		for i, b := range key {
			if 'A' <= b && b <= 'Z' {
				key[i] = b + 'a' - 'A'
			}
		}
	}
	sum := sha256.Sum256(key)
	return At(filepath.Join(root, FoldersName, "f"+hex.EncodeToString(sum[:])[:16]))
}

// Path is the directory itself.
func (d Dir) Path() string {
	return d.path
}

func (d Dir) lease() string {
	return filepath.Join(d.path, LeaseName)
}

// Channel is the stop channel a running server's stop record names.
type Channel struct {
	Port  int    `json:"port"`
	Token string `json:"token"`
}

// StopChannel reads the stop record. A missing record is fs.ErrNotExist: the
// start that holds the lease has not answered yet.
func (d Dir) StopChannel() (Channel, error) {
	content, err := os.ReadFile(filepath.Join(d.path, stopRecordName))
	if err != nil {
		return Channel{}, err
	}
	var channel Channel
	return channel, json.Unmarshal(content, &channel)
}

// Stop asks the server to stop the way Ctrl+C stops it. It returns "" when the
// channel accepts, otherwise the status line it answered, or "no answer".
func (c Channel) Stop() string {
	request, err := http.NewRequest(http.MethodPost, fmt.Sprintf("http://127.0.0.1:%d/stop", c.Port), nil)
	if err != nil {
		return err.Error()
	}
	request.Header.Set("Authorization", "Bearer "+c.Token)
	response, err := (&http.Client{Timeout: 10 * time.Second}).Do(request)
	if err != nil {
		return "no answer"
	}
	response.Body.Close()
	if response.StatusCode == http.StatusNoContent {
		return ""
	}
	return response.Proto + " " + response.Status
}

// WaitFree polls the lease until no server holds it, for limit at most.
func (d Dir) WaitFree(limit time.Duration) (bool, error) {
	deadline := time.Now().Add(limit)
	for {
		held, err := d.Lease()
		if err != nil {
			return false, err
		}
		if !held {
			return true, nil
		}
		if !time.Now().Before(deadline) {
			return false, nil
		}
		time.Sleep(pollInterval)
	}
}
