package main

// runtimePayload and runtimeManifest carry the compressed runtime build and the
// manifest describing what it holds. appPayload and appChecksum carry the
// compressed application and the name of its cache entry. siteName and
// siteVersion name the packaged site and its release. engine marks the engine
// executable, whose application holds the engine's files alone. nodePayload
// and nodeManifest carry the site's Node release, and are empty for a file
// that carries none.
// launcher/cmd/pack replaces this file before it builds the launcher.
var (
	runtimePayload  []byte
	runtimeManifest []byte
	appPayload      []byte
	nodePayload     []byte
	nodeManifest    []byte
	appChecksum     []byte
	siteName        string
	siteVersion     string
	engine          bool
)
