package main

import (
	"fmt"
	"path/filepath"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/caddyfile"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
)

// logFiles returns the files of --access-log and --error-log, each "" for a
// start without its option. A bare option writes its default file in directory.
func logFiles(o options, directory string, served []string, fold bool) (access, errors string, err error) {
	if access, err = logFile(o.AccessLog, accessLogOption.name, directory+"/access.log", served, fold); err != nil {
		return "", "", err
	}
	if errors, err = logFile(o.ErrorLog, errorLogOption.name, directory+"/error.log", served, fold); err != nil {
		return "", "", err
	}
	if access != "" && holds(access, errors, fold) {
		return "", "", fmt.Errorf("--%s and --%s name one file: %s", accessLogOption.name, errorLogOption.name, access)
	}
	return access, errors, nil
}

// logFile returns the file the log option name writes: "" for a nil written,
// fallback for the bare option, else the file the reader named, from the
// working directory. served are the directories a port answers from.
// It refuses a file the Caddyfile cannot hold, and one a port would serve.
func logFile(written *string, name, fallback string, served []string, fold bool) (string, error) {
	switch {
	case written == nil:
		return "", nil
	case *written == "":
		return fallback, nil
	}
	absolute, err := filepath.Abs(*written)
	if err != nil {
		return "", err
	}
	// A link in the folder can lead into a served directory. The file itself may not exist yet.
	file := resolved(runtime.Canonical(filepath.Dir(absolute))) + "/" + filepath.Base(absolute)
	if err := caddyfile.RefuseBraces("--"+name, file); err != nil {
		return "", err
	}
	for _, directory := range served {
		if holds(resolved(directory), file, fold) {
			return "", fmt.Errorf("--%s names %s inside %s, which the server answers requests from. Name a file outside it.", name, file, directory)
		}
	}
	return file, nil
}
