package runtime

import (
	"fmt"
	"io"
)

// Release is the runtime build and the application one executable carries.
// Neither runs with a component from another release.
type Release struct {
	// Libc names the runtime build Select picked, empty on a launcher carrying one.
	Libc        string
	Payload     []byte
	Manifest    Manifest
	AppChecksum string
	AppPayload  []byte
}

// PrepareRelease readies r's runtime and application under root and returns
// both directories. The runtime turns active once both are ready, so a failed
// step leaves the release an earlier start activated in place.
func PrepareRelease(root string, r Release, notice io.Writer) (string, string, error) {
	runtimeDir, err := Prepare(root, r.Payload, r.Manifest, notice)
	if err != nil {
		return "", "", r.failure("runtime", err)
	}
	application, err := PrepareApp(root, r.AppChecksum, r.AppPayload, notice)
	if err != nil {
		return "", "", r.failure("application", err)
	}
	if err := Activate(root, runtimeDir); err != nil {
		return "", "", r.failure("activation", err)
	}
	return runtimeDir, application, nil
}

// failure names what the start asked for and the step that stopped it.
func (r Release) failure(step string, err error) error {
	build := r.Libc
	if build == "" {
		build = "single"
	}
	return fmt.Errorf("could not prepare runtime %s (%s build) with application %s, at the %s step: %w",
		Key(r.Manifest.Version, r.Payload), build, r.AppChecksum, step, err)
}
