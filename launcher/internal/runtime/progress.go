package runtime

import (
	"fmt"
	"io"
	"os"
	"strings"
)

// megabyte is the unit progress reports name, decimal so the figures match the
// size a file manager shows for the executable.
const megabyte = 1_000_000

// progressReports is how many reports one unpacking writes before its last one.
const progressReports = 10

// barCells is the drawn width of the bar, in characters.
const barCells = 24

// progress reports how much of a payload an unpacking has read. Unpacking
// takes long enough that a terminal showing nothing reads as a hang, so the
// reports name a figure that keeps moving.
type progress struct {
	notice   io.Writer
	total    int64
	read     int64
	reported int64
	bar      bool
	drawn    bool
}

// newProgress returns a progress for a payload of total bytes. A terminal gets
// one bar rewritten in place. Anything else gets one line per step, since a
// carriage return leaves a redirected log unreadable.
func newProgress(total int64, notice io.Writer) *progress {
	return &progress{total: total, notice: notice, bar: characterDevice(notice)}
}

// characterDevice reports whether w is a terminal rather than a file or a pipe.
func characterDevice(w io.Writer) bool {
	file, ok := w.(*os.File)
	if !ok {
		return false
	}
	info, err := file.Stat()
	return err == nil && info.Mode()&os.ModeCharDevice != 0
}

// reading returns source wrapped so every read counts toward the reports. A
// payload under a megabyte unpacks faster than a reader reads one line, and
// reporting it in megabytes would name zero throughout, so it gets none.
func (p *progress) reading(source io.Reader) io.Reader {
	if p.total < megabyte {
		return source
	}
	return &countingReader{progress: p, source: source}
}

// advance counts n more bytes and reports once each step of the payload passes.
func (p *progress) advance(n int) {
	p.read += int64(n)
	if p.read-p.reported >= p.total/progressReports {
		p.reported = p.read
		p.report(p.read)
	}
}

// last names the whole payload, so the closing report matches the total even
// when the final read fell short of a step. A drawn bar ends its line here,
// where the next writer would otherwise overwrite it.
func (p *progress) last() {
	if p.total < megabyte {
		return
	}
	if p.reported < p.total {
		p.report(p.total)
	}
	if p.drawn {
		fmt.Fprintln(p.notice)
	}
}

func (p *progress) report(read int64) {
	if !p.bar {
		fmt.Fprintf(p.notice, "  %d of %d MB\n", read/megabyte, p.total/megabyte)
		return
	}
	filled := int(read * barCells / p.total)
	fmt.Fprintf(p.notice, "\r  [%s%s] %3d%%  %d of %d MB",
		strings.Repeat("#", filled), strings.Repeat("-", barCells-filled),
		read*100/p.total, read/megabyte, p.total/megabyte)
	p.drawn = true
}

type countingReader struct {
	progress *progress
	source   io.Reader
}

func (c *countingReader) Read(buffer []byte) (int, error) {
	n, err := c.source.Read(buffer)
	c.progress.advance(n)
	return n, err
}
