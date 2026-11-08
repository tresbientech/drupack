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
	"strings"
	"testing"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/state"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/state/statetest"
)

// The keys the previous release's PHP gave these paths, printed by its hash and strtolower.
func TestFolderEntryMatchesThePreviousRelease(t *testing.T) {
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

func TestClaim(t *testing.T) {
	dir := t.TempDir()
	held := state.At(dir)
	lease, err := held.Claim()
	if err != nil || lease == nil {
		t.Fatalf("Claim() = %v, %v", lease, err)
	}
	t.Cleanup(func() { lease.Release() })
	if on, err := held.Lease(); err != nil || !on {
		t.Errorf("Lease() after Claim() = %v, %v", on, err)
	}
	if second, err := held.Claim(); err != nil || second != nil {
		t.Errorf("a second Claim() = %v, %v, want nil", second, err)
	}
	other := t.TempDir()
	statetest.HoldLease(t, other)
	if taken, err := state.At(other).Claim(); err != nil || taken != nil {
		t.Errorf("Claim() against a PHP flock holder = %v, %v, want nil", taken, err)
	}
}

func TestStopRecord(t *testing.T) {
	dir := state.At(t.TempDir())
	if err := dir.ClearStopRecord(); err != nil {
		t.Fatalf("no record: %v", err)
	}
	if err := os.WriteFile(dir.StopRecordPath(), []byte(`{"port":1,"token":"x"}`), 0o600); err != nil {
		t.Fatal(err)
	}
	if err := dir.ClearStopRecord(); err != nil {
		t.Fatal(err)
	}
	if _, err := dir.StopChannel(); !errors.Is(err, fs.ErrNotExist) {
		t.Errorf("StopChannel() after the clear = %v", err)
	}
}

func TestBundleAndFolderRecords(t *testing.T) {
	dir := state.At(t.TempDir())
	if err := dir.WriteBundle([]byte("roots")); err != nil {
		t.Fatal(err)
	}
	if err := dir.WriteBundle([]byte("roots and ours")); err != nil {
		t.Fatal(err)
	}
	path, ok := dir.Bundle()
	if content, _ := os.ReadFile(path); !ok || string(content) != "roots and ours" {
		t.Errorf("Bundle() = %q, %v, %q", path, ok, content)
	}
	if entries, _ := os.ReadDir(dir.Path()); len(entries) != 1 {
		t.Errorf("the replace left %d files", len(entries))
	}
	if err := dir.RemoveBundle(); err != nil {
		t.Fatal(err)
	}
	if err := dir.RemoveBundle(); err != nil {
		t.Errorf("removing no bundle: %v", err)
	}
	if _, ok := dir.Bundle(); ok {
		t.Error("the bundle is still there")
	}
	written := state.Listener{Listen: "127.0.0.1:3787", Host: "shop.drupack.site", Scheme: "https", StaticListen: "127.0.0.1:3788"}
	if err := dir.WriteListener(written); err != nil {
		t.Fatal(err)
	}
	if got, ok, err := dir.Listener(); err != nil || !ok || got != written {
		t.Errorf("Listener() = %+v, %v, %v, want %+v", got, ok, err, written)
	}
	// A start without a static listener or a files directory records null for both, as Site data does.
	if err := dir.WriteListener(state.Listener{Listen: "[::1]:80", Host: "::1", Scheme: "http"}); err != nil {
		t.Fatal(err)
	}
	content, _ := os.ReadFile(filepath.Join(dir.Path(), "listener"))
	if want := `{"listen":"[::1]:80","host":"::1","files-dir":null,"scheme":"http","static-listen":null}`; string(content) != want {
		t.Errorf("listener = %s, want %s", content, want)
	}
}

func TestMercureSecretsAreCreatedOnceAndReadBack(t *testing.T) {
	dir := state.At(t.TempDir())
	publisher, subscriber, err := dir.CreateMercureSecrets()
	if err != nil || len(publisher) != 64 || len(subscriber) != 64 || publisher == subscriber {
		t.Fatalf("CreateMercureSecrets() = %q, %q, %v", publisher, subscriber, err)
	}
	again, _, err := dir.CreateMercureSecrets()
	if err != nil || again != publisher {
		t.Errorf("a second call = %q, %v, want the first secret", again, err)
	}
	read, _, ok, err := dir.MercureSecrets()
	if err != nil || !ok || read != publisher {
		t.Errorf("MercureSecrets() = %q, %v, %v", read, ok, err)
	}
	blocked := state.At(t.TempDir())
	if err := os.Mkdir(filepath.Join(blocked.Path(), "mercure_publisher_secret"), 0o700); err != nil {
		t.Fatal(err)
	}
	if _, _, err := blocked.CreateMercureSecrets(); err == nil || !strings.HasPrefix(err.Error(), "Cannot create the Mercure secret: ") {
		t.Errorf("a directory in the secret's place = %v", err)
	}
}
