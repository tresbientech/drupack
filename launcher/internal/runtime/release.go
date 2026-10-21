package runtime

import (
	"fmt"
	"io"
)

// Release is the runtime build and the application one executable carries.
// Neither runs with a component from another release.
type Release struct {
	Payload     []byte
	Manifest    Manifest
	AppChecksum string
	AppPayload  []byte
}

// PrepareRelease readies r's runtime and application and returns both
// directories, holding each before its cache lock releases. The runtime turns
// active once both are ready, so a failed step leaves the release an earlier
// start activated in place.
func (c Cache) PrepareRelease(r Release, notice io.Writer) (string, string, error) {
	runtimeDir, err := c.Prepare(r.Payload, r.Manifest, notice)
	if err != nil {
		return "", "", r.failure("runtime", err)
	}
	application, err := c.PrepareApp(r.AppChecksum, r.AppPayload, notice)
	if err != nil {
		return "", "", r.failure("application", err)
	}
	if err := c.Activate(runtimeDir); err != nil {
		return "", "", r.failure("activation", err)
	}
	return runtimeDir, application, nil
}

func (r Release) failure(step string, err error) error {
	return fmt.Errorf("could not prepare runtime %s with application %s, at the %s step: %w",
		Key(r.Manifest.Version, r.Payload), r.AppChecksum, step, err)
}

// Target names what one packed file runs on, as a release asset does: its
// platform, with a -musl suffix for the musl runtime.
func Target(platform string, musl bool) string {
	if musl {
		return platform + "-musl"
	}
	return platform
}
