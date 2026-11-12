package payload

import (
	"bytes"
	"io"
	"strings"
	"testing"
)

func TestProgressReportsEachStep(t *testing.T) {
	notice := &bytes.Buffer{}
	total := 100 * Megabyte
	p := &progress{total: int64(total), notice: notice}
	reader := p.reading(bytes.NewReader(make([]byte, total)))

	buffer := make([]byte, Megabyte)
	for {
		_, err := reader.Read(buffer)
		if err == io.EOF {
			break
		}
		if err != nil {
			t.Fatalf("read failed: %v", err)
		}
	}
	p.last()

	reports := strings.Split(strings.TrimSuffix(notice.String(), "\n"), "\n")
	if len(reports) != progressReports {
		t.Fatalf("wrote %d reports, wanted %d: %q", len(reports), progressReports, reports)
	}
	if got, want := reports[0], "  10 of 100 MB"; got != want {
		t.Errorf("first report is %q, wanted %q", got, want)
	}
	if got, want := reports[len(reports)-1], "  100 of 100 MB"; got != want {
		t.Errorf("last report is %q, wanted %q", got, want)
	}
}

func TestProgressLastNamesTheTotal(t *testing.T) {
	notice := &bytes.Buffer{}
	total := 15 * Megabyte
	p := &progress{total: int64(total), notice: notice}
	if _, err := io.Copy(io.Discard, p.reading(bytes.NewReader(make([]byte, total)))); err != nil {
		t.Fatalf("copy failed: %v", err)
	}
	p.last()

	reports := strings.Split(strings.TrimSuffix(notice.String(), "\n"), "\n")
	if got, want := reports[len(reports)-1], "  15 of 15 MB"; got != want {
		t.Errorf("last report is %q, wanted %q", got, want)
	}
}
