// The builds copy tls.go into FrankenPHP's own main package beside the entry point
// and never read this file. It lets `go test` build the TLS code without FrankenPHP.
module git.tresbien.tech/tresbientech/drupack/runtime/tls

go 1.25

require github.com/smallstep/truststore v0.13.0

require howett.net/plist v1.0.0 // indirect
