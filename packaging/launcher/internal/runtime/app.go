package runtime

import (
	"archive/tar"
	"bytes"
	"fmt"
	"io"
	"os"
	"path/filepath"

	"github.com/klauspost/compress/zstd"
)

// appDirName holds every unpacked application, one directory per release.
const appDirName = "app"

// completeName marks an application directory as fully written. It is the last
// file created, so a directory without it was interrupted and unpacks again.
const completeName = ".complete"

// AppRoot returns the directory holding unpacked applications under root.
func AppRoot(root string) string {
	return filepath.Join(root, appDirName)
}

// PrepareApp returns the directory holding the application that checksum
// names, unpacking payload there the first time this release is seen. A
// directory carrying the completion marker is used as it stands. Unpacking
// happens under the root lock, into a staging directory that moves into place
// once the marker is written, so a start interrupted halfway leaves nothing a
// later start mistakes for a finished copy.
func PrepareApp(root, checksum string, payload []byte, notice io.Writer) (string, error) {
	if !SingleElement(checksum) {
		return "", fmt.Errorf("application checksum is not a single path element: %q", checksum)
	}
	appRoot := AppRoot(root)
	entry := filepath.Join(appRoot, checksum)
	if complete(entry) {
		return entry, nil
	}
	if err := os.MkdirAll(appRoot, rootMode); err != nil {
		return "", err
	}

	unlock, err := lockRoot(appRoot)
	if err != nil {
		return "", fmt.Errorf("could not lock the application cache %s: %w", appRoot, err)
	}
	defer unlock()

	// Another process may have finished while this one waited on the lock.
	if complete(entry) {
		return entry, nil
	}

	fmt.Fprintf(notice, "Unpacking the Drupack application. This happens once for each release.\n")

	staging := filepath.Join(appRoot, stagingPrefix+checksum)
	if err := os.RemoveAll(staging); err != nil {
		return "", err
	}
	if err := extractApp(staging, payload); err != nil {
		os.RemoveAll(staging)
		return "", err
	}
	if err := os.WriteFile(filepath.Join(staging, completeName), nil, 0600); err != nil {
		os.RemoveAll(staging)
		return "", err
	}
	if err := os.RemoveAll(entry); err != nil {
		return "", err
	}
	if err := os.Rename(staging, entry); err != nil {
		os.RemoveAll(staging)
		return "", err
	}
	return entry, nil
}

// complete reports whether entry holds a fully unpacked application.
func complete(entry string) bool {
	_, err := os.Stat(filepath.Join(entry, completeName))
	return err == nil
}

// extractApp unpacks payload, a zstd-compressed tar stream, into destination.
// The archive arrives inside this executable, so its paths are checked rather
// than trusted: a crafted binary is a different problem, but a path outside
// destination is refused here whatever produced it.
func extractApp(destination string, payload []byte) error {
	decoder, err := zstd.NewReader(bytes.NewReader(payload))
	if err != nil {
		return err
	}
	defer decoder.Close()

	reader := tar.NewReader(decoder)
	for {
		header, err := reader.Next()
		if err == io.EOF {
			return nil
		}
		if err != nil {
			return err
		}
		name := filepath.Clean(filepath.FromSlash(header.Name))
		if name == "." {
			continue
		}
		if !SafePath(name) {
			return fmt.Errorf("application archive holds an unsafe path: %s", header.Name)
		}
		path := filepath.Join(destination, name)
		switch header.Typeflag {
		case tar.TypeDir:
			if err := os.MkdirAll(path, 0700); err != nil {
				return err
			}
		case tar.TypeReg:
			if err := writeAppFile(path, reader, header.FileInfo().Mode()); err != nil {
				return err
			}
		default:
			return fmt.Errorf("application archive holds an entry that is not a file or directory: %s", header.Name)
		}
	}
}

func writeAppFile(path string, contents io.Reader, mode os.FileMode) error {
	if err := os.MkdirAll(filepath.Dir(path), 0700); err != nil {
		return err
	}
	// The site writes into its own data directory, never here, so the unpacked
	// tree keeps the archive's own modes with write added for the owner alone.
	output, err := os.OpenFile(path, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, mode.Perm()|0600)
	if err != nil {
		return err
	}
	_, err = io.Copy(output, contents)
	closeErr := output.Close()
	if err == nil {
		err = closeErr
	}
	return err
}
