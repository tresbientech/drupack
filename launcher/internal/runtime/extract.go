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
		if err := extractFile(destination, header.Name, reader); err != nil {
			return err
		}
	}
}

func extractFile(destination, name string, contents io.Reader) error {
	path := filepath.Join(destination, filepath.FromSlash(name))
	if err := os.MkdirAll(filepath.Dir(path), 0700); err != nil {
		return err
	}
	output, err := os.OpenFile(path, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0700)
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
