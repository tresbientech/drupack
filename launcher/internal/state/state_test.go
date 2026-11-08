package state_test

import (
	"errors"
	"io/fs"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"path/filepath"
	"strconv"
	"testing"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/state"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/state/statetest"
)

// The keys serve.php's folderEntry() gives these paths, printed by PHP's hash and strtolower.
func TestFolderEntryMatchesServePhp(t *testing.T) {
	for _, row := range []struct {
		project string
		fold    bool
		key     string
	}{
		{"/home/reader/My Project", false, "8cfc705b73d35f97"},
		{"/home/reader/My Project", true, "3e42cf3ff68e2b84"},
		{"C:/Users/Reader/Projet Été", false, "1f7bc805b29ea668"},
		// strtolower folds ASCII letters alone, so É keeps its case.
		{"C:/Users/Reader/Projet Été", true, "65259303d4e51dc6"},
	} {
		want := state.At(filepath.Join("root", state.FoldersName, "f"+row.key))
		if got := state.FolderEntry("root", row.project, row.fold); got != want {
			t.Errorf("FolderEntry(%q, fold %v) = %v, want %v", row.project, row.fold, got, want)
		}
	}
}

func TestLease(t *testing.T) {
	held := t.TempDir()
	statetest.HoldLease(t, held)
	free := t.TempDir()
	if err := os.WriteFile(filepath.Join(free, state.LeaseName), nil, 0o600); err != nil {
		t.Fatal(err)
	}
	directory := t.TempDir()
	if err := os.Mkdir(filepath.Join(directory, state.LeaseName), 0o700); err != nil {
		t.Fatal(err)
	}
	file := filepath.Join(t.TempDir(), "data")
	if err := os.WriteFile(file, nil, 0o600); err != nil {
		t.Fatal(err)
	}
	for _, row := range []struct {
		name string
		dir  string
		held bool
	}{
		{"a lease a server holds", held, true},
		{"a lease nobody holds", free, false},
		{"no lease file", t.TempDir(), false},
		{"a directory in the lease's place", directory, false},
		{"a state path that names a file", file, false},
		{"a state path that does not exist", filepath.Join(t.TempDir(), "missing"), false},
	} {
		got, err := state.At(row.dir).Lease()
		if err != nil || got != row.held {
			t.Errorf("%s: Lease() = %v, %v, want %v", row.name, got, err, row.held)
		}
	}
}

// The runtime's watch writes the record the fixture holds.
func TestStopChannelReadsTheRuntimesRecord(t *testing.T) {
	record, err := os.ReadFile(filepath.Join("..", "..", "..", "runtime", "watch", "testdata", "stop.json"))
	if err != nil {
		t.Fatal(err)
	}
	dir := t.TempDir()
	if err := os.WriteFile(filepath.Join(dir, "stop.json"), record, 0o600); err != nil {
		t.Fatal(err)
	}
	channel, err := state.At(dir).StopChannel()
	if err != nil || channel != (state.Channel{Port: 49152, Token: "fixture-token"}) {
		t.Errorf("StopChannel() = %+v, %v", channel, err)
	}
	if _, err := state.At(t.TempDir()).StopChannel(); !errors.Is(err, fs.ErrNotExist) {
		t.Errorf("StopChannel() without a record = %v, want fs.ErrNotExist", err)
	}
}

// channel answers status to a POST of /stop that carries the token, and 400 to anything else.
func channel(t *testing.T, status int) state.Channel {
	t.Helper()
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost || r.URL.Path != "/stop" || r.Header.Get("Authorization") != "Bearer token" {
			w.WriteHeader(http.StatusBadRequest)
			return
		}
		w.WriteHeader(status)
	}))
	t.Cleanup(server.Close)
	address, err := url.Parse(server.URL)
	if err != nil {
		t.Fatal(err)
	}
	port, err := strconv.Atoi(address.Port())
	if err != nil {
		t.Fatal(err)
	}
	return state.Channel{Port: port, Token: "token"}
}

func TestStop(t *testing.T) {
	if got := channel(t, http.StatusNoContent).Stop(); got != "" {
		t.Errorf("an accepted stop = %q, want \"\"", got)
	}
	if got := channel(t, http.StatusForbidden).Stop(); got != "HTTP/1.1 403 Forbidden" {
		t.Errorf("a refused stop = %q", got)
	}
	closed := channel(t, http.StatusNoContent)
	listener := httptest.NewServer(http.NotFoundHandler())
	listener.Close()
	address, _ := url.Parse(listener.URL)
	closed.Port, _ = strconv.Atoi(address.Port())
	if got := closed.Stop(); got != "no answer" {
		t.Errorf("a stop nobody answers = %q", got)
	}
}

func TestWaitFree(t *testing.T) {
	if free, err := state.At(t.TempDir()).WaitFree(0); !free || err != nil {
		t.Errorf("WaitFree on a free lease = %v, %v", free, err)
	}
	held := t.TempDir()
	statetest.HoldLease(t, held)
	if free, err := state.At(held).WaitFree(0); free || err != nil {
		t.Errorf("WaitFree on a held lease = %v, %v", free, err)
	}
}
