package main

// payload and manifestData carry the compressed runtime and its manifest.
// packaging/launcher/cmd/pack replaces this file before it builds the launcher.
var (
	payload      []byte
	manifestData []byte
)
