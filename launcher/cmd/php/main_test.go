package main

import (
	"encoding/json"
	"os"
	"path/filepath"
	"slices"
	"testing"
)

// TestMain stands in for the runtime when the test runs its own binary: it writes
// the arguments it got to the file PHP_SHIM_TEST_ARGV names and exits 3.
func TestMain(m *testing.M) {
	if record := os.Getenv("PHP_SHIM_TEST_ARGV"); record != "" {
		written, _ := json.Marshal(os.Args[1:])
		if err := os.WriteFile(record, written, 0o600); err != nil {
			os.Exit(1)
		}
		os.Exit(3)
	}
	os.Exit(m.Run())
}

func TestRunPassesTheArgumentsToThePhpWordAndReturnsItsStatus(t *testing.T) {
	record := filepath.Join(t.TempDir(), "argv.json")
	t.Setenv("PHP_SHIM_TEST_ARGV", record)
	arguments := []string{"-d", "disable_functions=", "-d", "memory_limit=1536M", "-r", `echo "a \"b\" c\\";`, "", "100%", "a b"}
	self, err := os.Executable()
	if err != nil {
		t.Fatal(err)
	}
	if status := run(self, arguments); status != 3 {
		t.Fatalf("run returned %d, want the runtime's 3", status)
	}
	content, err := os.ReadFile(record)
	if err != nil {
		t.Fatal(err)
	}
	var got []string
	if err := json.Unmarshal(content, &got); err != nil {
		t.Fatal(err)
	}
	if want := append([]string{"php"}, arguments...); !slices.Equal(got, want) {
		t.Errorf("the runtime got %q, want %q", got, want)
	}
}

func TestRunReturnsOneWhenTheRuntimeIsMissing(t *testing.T) {
	if status := run(filepath.Join(t.TempDir(), "frankenphp.exe"), nil); status != 1 {
		t.Errorf("run returned %d, want 1", status)
	}
}
