// The builds copy watch.go into FrankenPHP's own main package beside the entry point
// and never read this file. It lets `go test` build the watcher without FrankenPHP.
module git.tresbien.tech/tresbientech/drupack/runtime/watch

go 1.25
