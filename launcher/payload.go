package main

// runtimePayload and runtimeManifest carry the compressed runtime build and the
// manifest describing what it holds. appPayload and appChecksum carry the
// compressed application and the name of its cache entry. siteName and
// siteVersion name the packaged site and its release. engine marks the engine
// executable, whose application holds the engine's files alone.
// launcher/cmd/pack replaces this file before it builds the launcher.
var (
	runtimePayload  []byte
	runtimeManifest []byte
	appPayload      []byte
	appChecksum     []byte
	siteName        string
	siteVersion     string
	engine          bool
)
