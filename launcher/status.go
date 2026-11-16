package main

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os/exec"
	"runtime"
	"strings"
	"time"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/siteconfig"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/state"
)

// drushWait bounds the drush status that status prints, the drain of its output included.
const drushWait = 10 * time.Second

// runStatus prints whether a server runs on c's target, where it serves, the
// line it started with and Drush's status. It exits 0 while a server answers,
// 3 with none and 4 while a start prepares, the codes an LSB status gives,
// whatever Drush answers.
func runStatus(l Launch, c command, out io.Writer) error {
	dir, what, named, found, err := serverDir(l, c)
	if err != nil {
		return err
	}
	held := false
	if found {
		if held, err = dir.Lease(); err != nil {
			return err
		}
	}
	if !held {
		fmt.Fprintf(out, "%s is not running.\n", l.Name)
		return exitError(3)
	}
	if _, err := dir.StopChannel(); errors.Is(err, fs.ErrNotExist) {
		fmt.Fprintf(out, "A %s start is still preparing this %s: %s\n", l.Name, what, named)
		return exitError(4)
	} else if err != nil {
		return err
	}
	a, err := Served(dir, l.Name, Site{Engine: l.Engine}, state.Listener{})
	if err != nil {
		return err
	}
	plan, err := a.Plan(a.StaticListen != "", "")
	if err != nil {
		return err
	}
	record, recorded, err := dir.Start()
	if err != nil {
		return err
	}
	static := ""
	if plan.Static != nil {
		static = plan.Static.URL
	}
	rows := [][2]string{{"URL:   ", strings.TrimSuffix(plan.URL, "/")}, {"Static:", static}}
	how := ""
	if recorded {
		logs := ""
		how = " in the foreground"
		if record.Background {
			how, logs = " in the background", logsDir(l.Engine, dir)
		}
		words := []string{l.Invoked}
		for _, word := range record.Words {
			words = append(words, shellWord(word, runtime.GOOS == "windows"))
		}
		rows = append(rows, [][2]string{{"Logs:  ", l.shown(logs)}, {"From:  ", l.shown(record.Cwd)}, {"Words: ", strings.Join(words, " ")}}...)
	}
	var summary strings.Builder
	writeRows(&summary, rows)
	fmt.Fprintf(out, "%s serves %s%s.\n\n%s", l.Name, l.shown(named), how, summary.String())
	report, err := drushStatus(l, c, named, drushWait)
	if err != nil {
		reason, _, _ := strings.Cut(err.Error(), "\n")
		fmt.Fprintf(out, "\nDrush status unavailable: %s\n", reason)
		return nil
	}
	fmt.Fprint(out, "\nDrush status:\n")
	for line := range strings.SplitSeq(strings.TrimRight(report, "\n"), "\n") {
		fmt.Fprintf(out, "  %s\n", line)
	}
	return nil
}

// drushStatus runs drush status for the server of named, a folder or Site data,
// and returns its output within wait.
func drushStatus(l Launch, c command, named string, wait time.Duration) (string, error) {
	f, words := Facts{Launch: l}, []string{"status", "--no-ansi"}
	var call Command
	if l.Engine {
		project, err := openFolder(l, named)
		if err != nil {
			return "", err
		}
		if call, err = project.drush(f, Drush, words); err != nil {
			return "", err
		}
		// The engine's Drush finds its project from the working directory.
		call.Dir = project.project
	} else {
		site, err := siteconfig.Load(l.Application)
		if err != nil {
			return "", err
		}
		c.options.DataDir = named
		opened, err := openSite(f, site, c, "")
		if err != nil {
			return "", err
		}
		call = opened.drush(Drush, words)
	}
	// A PHP child Drush starts can hold the output open past the kill, so its drain shares the budget.
	ctx, cancel := context.WithTimeout(context.Background(), wait-time.Second)
	defer cancel()
	child := exec.CommandContext(ctx, call.Path, call.Args[1:]...)
	child.Env, child.Dir, child.WaitDelay = call.Env, call.Dir, time.Second
	var stderr bytes.Buffer
	child.Stderr = &stderr
	report, err := child.Output()
	var exit *exec.ExitError
	switch {
	case ctx.Err() != nil:
		return "", fmt.Errorf("no answer within %d seconds", int(wait.Seconds()))
	case errors.As(err, &exit):
		line, _, _ := strings.Cut(strings.TrimSpace(stderr.String()), "\n")
		return "", fmt.Errorf("exit %d: %s", exit.ExitCode(), line)
	case err != nil:
		return "", err
	}
	return string(report), nil
}
