package main

// runtimes carries the compressed runtime builds this launcher can run, the
// ones needing a host loader ahead of the ones needing none. appPayload and
// appChecksum carry the compressed application and the name of its cache
// entry. siteName and siteVersion name the packaged site and its release.
// launcher/cmd/pack replaces this file before it builds the launcher.
var (
	runtimes    []embeddedRuntime
	appPayload  []byte
	appChecksum []byte
	siteName    string
	siteVersion string
)
