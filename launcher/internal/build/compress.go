package build

import (
	"io"
	"os"
	"path/filepath"

	"github.com/klauspost/compress/zstd"
)

// Names in the application payload: build/app-payload.sh writes the tar and the checksum,
// compressPayload the compressed copy every pack step carries, NodeDirName holds Node.
const (
	PayloadName    = "app-payload.tar.zst"
	ChecksumName   = "app_checksum.txt"
	NodeDirName    = "node"
	payloadTarName = "app-payload.tar"
)

// compressPayload compresses payload/app-payload.tar once into payload/app-payload.tar.zst.
func compressPayload(payload string) error {
	raw, err := os.Open(filepath.Join(payload, payloadTarName))
	if err != nil {
		return err
	}
	defer raw.Close()
	out, err := os.Create(filepath.Join(payload, PayloadName))
	if err != nil {
		return err
	}
	compressor, err := zstd.NewWriter(out, zstd.WithEncoderLevel(zstd.SpeedBestCompression))
	if err != nil {
		out.Close()
		return err
	}
	_, err = io.Copy(compressor, raw)
	if closeErr := compressor.Close(); err == nil {
		err = closeErr
	}
	if closeErr := out.Close(); err == nil {
		err = closeErr
	}
	return err
}
