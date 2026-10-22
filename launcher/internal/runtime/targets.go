package runtime

import (
	_ "embed"
	"encoding/json"
	"fmt"
)

// targets.json is the one table of build targets. The release workflow and
// build/release-files.py read the same file.
//
//go:embed targets.json
var targetsJSON []byte

// Libc is one C library a Linux file is built against. Builder names its
// builder image, and Suffix ends the name of each target built against it.
type Libc struct {
	Name    string `json:"name"`
	Builder string `json:"builder"`
	Suffix  string `json:"suffix"`
}

// Platforms maps each platform a build packs for to its GOARCH.
var Platforms map[string]string

// Libcs lists the C libraries, glibc first, so a build that packs both tests its glibc file.
var Libcs []Libc

func init() {
	table := struct {
		Platforms *map[string]string `json:"platforms"`
		Libcs     *[]Libc            `json:"libcs"`
	}{&Platforms, &Libcs}
	if err := json.Unmarshal(targetsJSON, &table); err != nil {
		panic(fmt.Sprintf("targets.json: %v", err))
	}
}

// Target names what one packed file runs on, as a release asset does: its
// platform and the suffix of libc. glibc's suffix is empty, so a macOS or
// Windows file passes glibc and keeps its platform's name.
func Target(platform, libc string) string {
	for _, known := range Libcs {
		if known.Name == libc {
			return platform + known.Suffix
		}
	}
	panic(fmt.Sprintf("libc %q is not in targets.json", libc))
}
