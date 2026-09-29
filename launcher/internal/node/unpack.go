package node

import (
	"archive/tar"
	"archive/zip"
	"compress/gzip"
	"fmt"
	"io"
	"os"
	"path"
	"path/filepath"
	"strings"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
)

// Unpack writes the files of a Node archive Resolve verified under
// destination, without the archive's top directory, and returns the path of
// Node's executable relative to destination.
func Unpack(archive, destination string) (string, error) {
	if strings.HasSuffix(archive, ".zip") {
		return "node.exe", unpackZip(archive, destination)
	}
	return "bin/node", unpackTar(archive, destination)
}

func unpackTar(archive, destination string) error {
	file, err := os.Open(archive)
	if err != nil {
		return err
	}
	defer file.Close()
	decompressed, err := gzip.NewReader(file)
	if err != nil {
		return fmt.Errorf("%s: %w", archive, err)
	}
	reader := tar.NewReader(decompressed)
	for {
		header, err := reader.Next()
		if err == io.EOF {
			return nil
		}
		if err != nil {
			return fmt.Errorf("%s: %w", archive, err)
		}
		name, ok := withinTop(header.Name)
		if !ok {
			continue
		}
		if !runtime.SafePath(name) {
			return fmt.Errorf("%s holds an unsafe path: %s", archive, header.Name)
		}
		switch header.Typeflag {
		case tar.TypeDir:
			err = os.MkdirAll(filepath.Join(destination, filepath.FromSlash(name)), 0o755)
		case tar.TypeReg:
			err = writeFile(destination, name, reader)
		case tar.TypeSymlink:
			err = writeLinkScript(destination, name, header.Linkname)
		default:
			err = fmt.Errorf("%s holds %s, which is neither a file, a directory nor a link", archive, header.Name)
		}
		if err != nil {
			return err
		}
	}
}

func unpackZip(archive, destination string) error {
	reader, err := zip.OpenReader(archive)
	if err != nil {
		return fmt.Errorf("%s: %w", archive, err)
	}
	defer reader.Close()
	for _, entry := range reader.File {
		name, ok := withinTop(entry.Name)
		if !ok {
			continue
		}
		if !runtime.SafePath(name) {
			return fmt.Errorf("%s holds an unsafe path: %s", archive, entry.Name)
		}
		mode := entry.Mode()
		switch {
		case mode.IsDir():
			err = os.MkdirAll(filepath.Join(destination, filepath.FromSlash(name)), 0o755)
		case mode.IsRegular():
			err = writeZipFile(destination, name, entry)
		default:
			err = fmt.Errorf("%s holds %s, which is neither a file nor a directory", archive, entry.Name)
		}
		if err != nil {
			return err
		}
	}
	return nil
}

// withinTop strips the node-vVERSION-PLATFORM directory every entry sits in,
// and reports false for that directory itself.
func withinTop(name string) (string, bool) {
	_, rest, found := strings.Cut(strings.TrimSuffix(name, "/"), "/")
	return rest, found && rest != ""
}

func writeZipFile(destination, name string, entry *zip.File) error {
	contents, err := entry.Open()
	if err != nil {
		return err
	}
	defer contents.Close()
	return writeFile(destination, name, contents)
}

func writeFile(destination, name string, contents io.Reader) error {
	target := filepath.Join(destination, filepath.FromSlash(name))
	if err := os.MkdirAll(filepath.Dir(target), 0o755); err != nil {
		return err
	}
	output, err := os.OpenFile(target, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o755)
	if err != nil {
		return err
	}
	_, err = io.Copy(output, contents)
	if closeErr := output.Close(); err == nil {
		err = closeErr
	}
	return err
}

// writeLinkScript replaces a link in bin, such as npm, with a script running
// the file it names with the node beside it. The launcher's payload carries
// regular files alone, and npm resolves its own modules from the real path of
// the script node runs.
func writeLinkScript(destination, name, link string) error {
	resolved := path.Join(path.Dir(name), link)
	if path.Dir(name) != "bin" || !strings.HasSuffix(link, ".js") || !runtime.SafePath(resolved) {
		return fmt.Errorf("the Node archive links %s to %s, and only a link in bin to a script is carried", name, link)
	}
	script := "#!/bin/sh\nexec \"$(dirname \"$0\")/node\" \"$(dirname \"$0\")/" + link + "\" \"$@\"\n"
	return writeFile(destination, name, strings.NewReader(script))
}
