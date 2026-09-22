package runtime_test

import (
	"strings"
	"testing"

	runtimepkg "git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
)

// presentInterpreter and absentInterpreter stand in for a host that carries a
// dynamic loader and one that does not. The root directory exists on every
// platform the launcher builds for.
const presentInterpreter = "/"
const absentInterpreter = "/drupack-no-such-interpreter.so"

func TestSelectRunsTheOnlyRuntimeWithoutReadingTheVariable(t *testing.T) {
	choices := []runtimepkg.Choice{{Libc: "", Interpreter: ""}}
	for _, libc := range []string{"", "musl", "glibc", "nonsense"} {
		index, err := runtimepkg.Select(choices, libc)
		if err != nil || index != 0 {
			t.Fatalf("Select(one runtime, %q) = %d, %v; want 0, nil", libc, index, err)
		}
	}
}

func TestSelectPrefersTheRuntimeThisHostCanRun(t *testing.T) {
	cases := map[string]struct {
		choices []runtimepkg.Choice
		want    int
	}{
		"host carries the loader": {
			choices: []runtimepkg.Choice{
				{Libc: "glibc", Interpreter: presentInterpreter},
				{Libc: "musl", Interpreter: ""},
			},
			want: 0,
		},
		"host carries no loader": {
			choices: []runtimepkg.Choice{
				{Libc: "glibc", Interpreter: absentInterpreter},
				{Libc: "musl", Interpreter: ""},
			},
			want: 1,
		},
	}
	for name, test := range cases {
		t.Run(name, func(t *testing.T) {
			index, err := runtimepkg.Select(test.choices, "")
			if err != nil {
				t.Fatalf("Select: %v", err)
			}
			if index != test.want {
				t.Fatalf("Select = %d, want %d", index, test.want)
			}
		})
	}
}

func TestSelectHonoursTheVariableOverTheHost(t *testing.T) {
	choices := []runtimepkg.Choice{
		{Libc: "glibc", Interpreter: presentInterpreter},
		{Libc: "musl", Interpreter: ""},
	}
	index, err := runtimepkg.Select(choices, "musl")
	if err != nil {
		t.Fatalf("Select: %v", err)
	}
	if index != 1 {
		t.Fatalf("Select = %d, want 1", index)
	}
}

func TestSelectRefusesAnUnknownLibc(t *testing.T) {
	choices := []runtimepkg.Choice{
		{Libc: "glibc", Interpreter: presentInterpreter},
		{Libc: "musl", Interpreter: ""},
	}
	_, err := runtimepkg.Select(choices, "gnu")
	if err == nil {
		t.Fatal("Select accepted an unknown libc")
	}
	for _, want := range []string{"DRUPACK_LIBC=gnu", "glibc", "musl"} {
		if !strings.Contains(err.Error(), want) {
			t.Fatalf("Select error %q does not name %q", err, want)
		}
	}
}

func TestSelectRefusesAHostNoCarriedRuntimeRunsOn(t *testing.T) {
	choices := []runtimepkg.Choice{
		{Libc: "glibc", Interpreter: absentInterpreter},
		{Libc: "musl", Interpreter: absentInterpreter},
	}
	if _, err := runtimepkg.Select(choices, ""); err == nil {
		t.Fatal("Select returned a runtime this host cannot run")
	}
}
