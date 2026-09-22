package runtime

import (
	"archive/tar"
	"bytes"
	"debug/elf"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"sort"

	"github.com/klauspost/compress/zstd"
)

// Build packs directory's regular files into a zstd-compressed tar stream and
// returns the manifest describing them. Every header carries the same mode,
// ownership and modification time, so one directory always packs to the same
// bytes.
func Build(directory, version, entry string) ([]byte, Manifest, error) {
	paths, err := collectFiles(directory)
	if err != nil {
		return nil, Manifest{}, err
	}

	var archive bytes.Buffer
	compressor, err := zstd.NewWriter(&archive, zstd.WithEncoderLevel(zstd.SpeedBestCompression))
	if err != nil {
		return nil, Manifest{}, err
	}
	tarWriter := tar.NewWriter(compressor)

	entryDeclared := false
	files := make([]File, 0, len(paths))
	for _, relative := range paths {
		if relative == entry {
			entryDeclared = true
		}
		full := filepath.Join(directory, filepath.FromSlash(relative))
		info, err := os.Stat(full)
		if err != nil {
			return nil, Manifest{}, err
		}
		sum, err := hashFile(full)
		if err != nil {
			return nil, Manifest{}, err
		}
		if err := writeTarFile(tarWriter, full, relative, info.Size()); err != nil {
			return nil, Manifest{}, err
		}
		files = append(files, File{Path: relative, Size: info.Size(), SHA256: sum})
	}

	if !entryDeclared {
		return nil, Manifest{}, fmt.Errorf("entry names no collected file: %s", entry)
	}
	if err := tarWriter.Close(); err != nil {
		return nil, Manifest{}, err
	}
	if err := compressor.Close(); err != nil {
		return nil, Manifest{}, err
	}

	interpreter, err := elfInterpreter(filepath.Join(directory, filepath.FromSlash(entry)))
	if err != nil {
		return nil, Manifest{}, err
	}

	return archive.Bytes(), Manifest{Version: version, Entry: entry, Interpreter: interpreter, Files: files}, nil
}

// elfMagic opens every ELF file. A Mach-O or PE entry starts with its own, and a
// file shorter than four bytes has none, so both answer "no interpreter" below.
var elfMagic = [4]byte{0x7f, 'E', 'L', 'F'}

// elfInterpreter returns the program interpreter path an ELF entry needs, and ""
// for a static ELF or for an entry of another format. The magic number is read
// here rather than left to elf.Open, which reports a short file as io.EOF and a
// wrong magic as a format error, two shapes for one answer.
func elfInterpreter(path string) (string, error) {
	handle, err := os.Open(path)
	if err != nil {
		return "", err
	}
	defer handle.Close()
	var magic [4]byte
	if _, err := io.ReadFull(handle, magic[:]); err != nil {
		if errors.Is(err, io.EOF) || errors.Is(err, io.ErrUnexpectedEOF) {
			return "", nil
		}
		return "", err
	}
	if magic != elfMagic {
		return "", nil
	}
	file, err := elf.NewFile(handle)
	if err != nil {
		return "", err
	}
	for _, program := range file.Progs {
		if program.Type != elf.PT_INTERP {
			continue
		}
		raw, err := io.ReadAll(program.Open())
		if err != nil {
			return "", err
		}
		return string(bytes.TrimRight(raw, "\x00")), nil
	}
	return "", nil
}

// writeTarFile appends one entry, header and content, to w. Every header uses
// a fixed mode, ownership and modification time so Build is reproducible.
func writeTarFile(w *tar.Writer, source, name string, size int64) error {
	header := &tar.Header{
		Name:     name,
		Size:     size,
		Mode:     0700,
		Typeflag: tar.TypeReg,
	}
	if err := w.WriteHeader(header); err != nil {
		return err
	}
	input, err := os.Open(source)
	if err != nil {
		return err
	}
	defer input.Close()
	_, err = io.Copy(w, input)
	return err
}

// collectFiles walks directory and returns its regular files' slash-separated
// paths relative to directory, sorted so Build's output depends only on
// content, never on directory traversal order.
func collectFiles(directory string) ([]string, error) {
	var paths []string
	err := filepath.WalkDir(directory, func(path string, entry fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		if entry.IsDir() || !entry.Type().IsRegular() {
			return nil
		}
		relative, err := filepath.Rel(directory, path)
		if err != nil {
			return err
		}
		paths = append(paths, filepath.ToSlash(relative))
		return nil
	})
	if err != nil {
		return nil, err
	}
	sort.Strings(paths)
	return paths, nil
}
