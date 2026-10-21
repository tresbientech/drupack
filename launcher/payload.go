package main

// launcher/cmd/pack replaces this file before it builds the launcher.
// nodePayload and nodeManifest are empty for a file with no Node. siteComponents holds
// one "Name version" line per part --version prints after the runtime's, and is empty
// for the engine, whose application holds the engine's files alone. musl marks a file
// packed over a musl runtime, whose release self-update fetches.
var (
	runtimePayload  []byte
	runtimeManifest []byte
	appPayload      []byte
	nodePayload     []byte
	nodeManifest    []byte
	appChecksum     []byte
	siteName        string
	siteVersion     string
	siteComponents  string
	engine          bool
	musl            bool
)
