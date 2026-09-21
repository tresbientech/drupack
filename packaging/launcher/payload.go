package main

// payload and manifestData carry the compressed runtime and its manifest.
// appPayload and appChecksum carry the compressed application and the name of
// its cache entry. packaging/launcher/cmd/pack replaces this file before it
// builds the launcher.
var (
	payload      []byte
	manifestData []byte
	appPayload   []byte
	appChecksum  []byte
)
