package payload

import (
	"archive/tar"
	"bytes"
	"fmt"
	"io"
	"os"
	"path/filepath"

	"github.com/klauspost/compress/zstd"
)

// Extract unpacks payload, a zstd-compressed tar stream, into destination.
// Every entry must be a regular file at a path m declares, and each file
// streams straight from the decoder to disk, since a runtime file can run
// several hundred megabytes decoded. It reports how far it has read to notice.
func Extract(destination string, payload []byte, m Manifest, notice io.Writer) error {
	declared := make(map[string]bool, len(m.Files))
	for _, file := range m.Files {
		declared[file.Path] = true
	}

	reports := newProgress(int64(len(payload)), notice)
	decoder, err := zstd.NewReader(reports.reading(bytes.NewReader(payload)))
	if err != nil {
		return err
	}
	defer decoder.Close()
	defer reports.last()

	reader := tar.NewReader(decoder)
	for {
		header, err := reader.Next()
		if err == io.EOF {
			return nil
		}
		if err != nil {
			return err
		}
		if !declared[header.Name] {
			return fmt.Errorf("payload contains an undeclared file: %s", header.Name)
		}
		if !SafePath(header.Name) {
			return fmt.Errorf("payload contains an unsafe path: %s", header.Name)
		}
		if header.Typeflag != tar.TypeReg {
			return fmt.Errorf("payload entry is not a regular file: %s", header.Name)
		}
		if err := WriteFile(filepath.Join(destination, filepath.FromSlash(header.Name)), reader, 0o700); err != nil {
			return err
		}
	}
}

// WriteFile streams contents to path with mode, creating the missing parent
// directories for the owner alone.
func WriteFile(path string, contents io.Reader, mode os.FileMode) error {
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return err
	}
	output, err := os.OpenFile(path, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, mode)
	if err != nil {
		return err
	}
	_, err = io.Copy(output, contents)
	if closeErr := output.Close(); err == nil {
		err = closeErr
	}
	return err
}

// ExtractApp unpacks payload, a zstd-compressed tar stream, into destination. Archive
// paths are checked, not trusted: a path outside destination is refused whatever produced it.
func ExtractApp(destination string, payload []byte, notice io.Writer) error {
	reports := newProgress(int64(len(payload)), notice)
	decoder, err := zstd.NewReader(reports.reading(bytes.NewReader(payload)))
	if err != nil {
		return err
	}
	defer decoder.Close()
	defer reports.last()

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
			if err := os.MkdirAll(path, 0o700); err != nil {
				return err
			}
		case tar.TypeReg:
			if err := WriteAppFile(path, reader, header.FileInfo().Mode()); err != nil {
				return err
			}
		default:
			return fmt.Errorf("application archive holds an entry that is not a file or directory: %s", header.Name)
		}
	}
}

// WriteAppFile keeps the archive's mode with write added for the owner alone:
// the site writes into its own data directory, never here.
func WriteAppFile(path string, contents io.Reader, mode os.FileMode) error {
	return WriteFile(path, contents, mode.Perm()|0o600)
}
