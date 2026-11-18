//go:build unix

package main

import (
	"io"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/cache"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/siteconfig"
)

// The stub stands in for the runtime: it answers Drush's user:login with stdout,
// and with stderr on standard error.
func TestMint(t *testing.T) {
	for _, row := range []struct {
		name, stdout, stderr string
		link, failure        string
	}{
		{"the link Drush mints at the URL", "http://localhost:3787/user/reset/1/abc\n", "", "http://localhost:3787/user/reset/1/abc", ""},
		{"the reason Drush gave when it mints no link", "", "Account is\nblocked", "", "Account is blocked"},
		{"a link that does not start with the URL", "http://elsewhere/user/reset/1/abc", "", "", ""},
		{"a link on a longer port", "http://localhost:37870/user/reset/1/abc", "", "", ""},
		{"a link naming the URL as its user", "http://localhost:3787@elsewhere/user/reset/1/abc", "", "", ""},
	} {
		stub := filepath.Join(t.TempDir(), "runtime")
		script := "#!/bin/sh\nprintf '%s' '" + row.stdout + "'\nprintf '%s' '" + row.stderr + "' >&2\n"
		if err := os.WriteFile(stub, []byte(script), 0o700); err != nil {
			t.Fatal(err)
		}
		link, failure, err := mint(Launch{Entry: stub}, nil, "http://localhost:3787", "drush.php", t.TempDir())
		if link != row.link || failure != row.failure || err != nil {
			t.Errorf("%s: mint = %q, %q, %v", row.name, link, failure, err)
		}
	}
}

// The stub refuses user:login with Drush's text. A blocked uid 1 gives each
// start's Login line a --name placeholder and prints no reason. Any other
// refusal prints its reason and gives no placeholder.
func TestLoginLineAfterAFailedMint(t *testing.T) {
	project := t.TempDir()
	drush := filepath.Join(project, cache.DrushScript)
	if err := os.MkdirAll(filepath.Dir(drush), 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(drush, nil, 0o600); err != nil {
		t.Fatal(err)
	}
	site := &packagedStart{Facts: Facts{Launch: Launch{Invoked: "./demo"}, Data: "/var/lib/demo"}}
	for _, row := range []struct {
		name, refusal, folder, site, reason string
	}{
		{"blocked", "Account admin is blocked and thus cannot login. The user:unblock command may be helpful.",
			"run ./drupack drush user:login --name=NAME --uri=http://localhost:3787",
			"run ./demo drush --data-dir /var/lib/demo user:login --name=NAME /admin/dashboard", ""},
		{"no database", "Unable to bootstrap Drupal.",
			"run ./drupack drush user:login --uri=http://localhost:3787",
			"run ./demo drush --data-dir /var/lib/demo user:login /admin/dashboard", "No login link: Unable to bootstrap Drupal.\n"},
	} {
		stub := filepath.Join(t.TempDir(), "runtime")
		if err := os.WriteFile(stub, []byte("#!/bin/sh\nprintf '%s' '"+row.refusal+"' >&2\nexit 1\n"), 0o700); err != nil {
			t.Fatal(err)
		}
		l := Launch{Invoked: "./drupack", Entry: stub, ConsoleOwned: true}
		read, write, err := os.Pipe()
		if err != nil {
			t.Fatal(err)
		}
		stderr := os.Stderr
		os.Stderr = write
		_, login, _, err := entrance(l, nil, project, nil, "http://localhost:3787")
		os.Stderr = stderr
		write.Close()
		printed, _ := io.ReadAll(read)
		if login != row.folder || err != nil {
			t.Errorf("%s: folder Login = %q, %v, want %q", row.name, login, err, row.folder)
		}
		if string(printed) != row.reason {
			t.Errorf("%s: printed %q, want %q", row.name, printed, row.reason)
		}
		_, failure, err := mint(l, nil, "http://localhost:3787", drush, project)
		if login := site.loginLine("", failure); login != row.site || err != nil {
			t.Errorf("%s: site Login = %q, %v, want %q", row.name, login, err, row.site)
		}
	}
}

// A buffered operation that fails always gives the reader a line: the child's
// refusal, or the run's error when the child printed none or never ran.
func TestOperateNamesTheCauseOfASilentFailure(t *testing.T) {
	folder := t.TempDir()
	for _, row := range []struct {
		name, script, want string
	}{
		{"refusal", "#!/bin/sh\necho 'No Drupal here.' >&2\nexit 1\n", "No Drupal here."},
		{"silent", "#!/bin/sh\nexit 3\n", "operate.php check: exit status 3"},
		{"absent", "", "operate.php check: fork/exec " + filepath.Join(folder, "absent") + ": no such file or directory"},
	} {
		stub := filepath.Join(folder, row.name)
		if row.script != "" {
			if err := os.WriteFile(stub, []byte(row.script), 0o700); err != nil {
				t.Fatal(err)
			}
		}
		var record struct{}
		if err := operate(Launch{Entry: stub}, nil, &record, false, "check", "site"); err == nil || err.Error() != row.want {
			t.Errorf("%s: operate = %v, want %q", row.name, err, row.want)
		}
	}
}

func overlapError(files, data string) string {
	return "The Files directory " + files + " overlaps private Site data in " + data + ". Point " + filepath.Join(data, "files") + " at another folder."
}

func linkFolder(t *testing.T, target, path string) {
	t.Helper()
	if err := os.MkdirAll(target, 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(target, path); err != nil {
		t.Fatal(err)
	}
}

func siteDataIn(t *testing.T, root string) string {
	t.Helper()
	data := filepath.Join(root, "data")
	if err := os.Mkdir(data, 0o700); err != nil {
		t.Fatal(err)
	}
	return data
}

// A default macOS volume ignores letter case, and a Linux one does not.
func TestSiteDirsRefuseAnotherCaseOfAPrivateFolder(t *testing.T) {
	for _, target := range []string{"DATA", "data/PRIVATE", "data/APP/web"} {
		root := realDir(t)
		data := siteDataIn(t, root)
		if _, err := os.Stat(filepath.Join(root, "DATA")); err != nil {
			t.Skip("this volume tells letter case apart")
		}
		linkFolder(t, filepath.Join(root, filepath.FromSlash(target)), filepath.Join(data, "files"))
		if _, _, err := siteDirs(data, "", false); err == nil || !strings.Contains(err.Error(), "overlaps private Site data") {
			t.Errorf("%s: error = %v", target, err)
		}
	}
}

func TestSiteDirsKeepPrivateDataOutOfPublicFiles(t *testing.T) {
	below := func(names ...string) func(*testing.T, string, string) string {
		return func(_ *testing.T, _, data string) string {
			return filepath.Join(append([]string{data}, names...)...)
		}
	}
	for _, row := range []struct {
		name    string
		target  func(t *testing.T, root, data string) string
		refused string // the resolved files folder a refusal names, relative to root
	}{
		{"Site data itself", below(), "data"},
		{"the folder above Site data", func(_ *testing.T, root, _ string) string { return root }, "."},
		{"the runtime folder", below("runtime"), "data/runtime"},
		{"the private folder", below("private"), "data/private"},
		{"below the private folder", below("private", "public"), "data/private/public"},
		{"the tmp folder", below("tmp"), "data/tmp"},
		{"the config folder", below("config"), "data/config"},
		{"the logs folder", below("logs"), "data/logs"},
		{"the static folder of a site without one", below("static"), "data/static"},
		{"below the application", below("app", "web"), "data/app/web"},
		{"the previous application", below(".previous-app"), "data/.previous-app"},
		{"the staged application", below(".staging-app"), "data/.staging-app"},
		{"a chain of links to the private folder", func(t *testing.T, root, data string) string {
			path := filepath.Join(root, "public")
			linkFolder(t, filepath.Join(data, "private"), path)
			return path
		}, "data/private"},
		{"a folder holding the target of a private link", func(t *testing.T, root, data string) string {
			linkFolder(t, filepath.Join(root, "public", "secret"), filepath.Join(data, "private"))
			return filepath.Join(root, "public")
		}, "public"},
		{"another folder in Site data", below("public"), ""},
		{"a folder whose name starts with a private one", below("private-public"), ""},
		{"a folder outside Site data", func(_ *testing.T, root, _ string) string { return filepath.Join(root, "public") }, ""},
	} {
		t.Run(row.name, func(t *testing.T) {
			root := realDir(t)
			data := siteDataIn(t, root)
			target := row.target(t, root, data)
			linkFolder(t, target, filepath.Join(data, "files"))
			files, _, err := siteDirs(data, "", false)
			if row.refused == "" {
				if err != nil {
					t.Fatalf("siteDirs refused a public folder: %v", err)
				}
				if files != target {
					t.Errorf("files = %s, want %s", files, target)
				}
				if _, err := os.Stat(filepath.Join(target, "translations")); err != nil {
					t.Error(err)
				}
				return
			}
			refused := filepath.Join(root, filepath.FromSlash(row.refused))
			if want := overlapError(refused, data); err == nil || err.Error() != want {
				t.Errorf("error = %v, want %q", err, want)
			}
			if _, err := os.Stat(filepath.Join(refused, "translations")); !os.IsNotExist(err) {
				t.Errorf("the refusal wrote through the link: %v", err)
			}
		})
	}
}

func TestSiteDirsCreateTheirOwnFilesFolder(t *testing.T) {
	data := siteDataIn(t, realDir(t))
	files, _, err := siteDirs(data, "", false)
	if want := filepath.Join(data, "files"); err != nil || files != want {
		t.Errorf("siteDirs = %s, %v, want %s", files, err, want)
	}
}

func TestSiteDirsRefuseADanglingFilesLink(t *testing.T) {
	root := realDir(t)
	data := siteDataIn(t, root)
	gone := filepath.Join(root, "gone")
	if err := os.Symlink(gone, filepath.Join(data, "files")); err != nil {
		t.Fatal(err)
	}
	if _, _, err := siteDirs(data, "", false); err == nil {
		t.Error("siteDirs accepted a dangling link")
	}
	if _, err := os.Stat(gone); !os.IsNotExist(err) {
		t.Errorf("the refusal created the link's target: %v", err)
	}
}

func overlappingRecord(recorded, files string) string {
	return "This Site data keeps its public files in " + recorded + ", which overlaps private Site data. Move the uploads into " + files +
		", then delete " + filepath.Join(filepath.Dir(files), "listener") + "."
}

func TestSiteDirsFollowARecordedFilesFolder(t *testing.T) {
	folder := func(t *testing.T, path string) {
		t.Helper()
		if err := os.Mkdir(path, 0o700); err != nil {
			t.Fatal(err)
		}
	}
	for _, row := range []struct {
		name    string
		prepare func(t *testing.T, files, outside string) (recorded string)
		want    func(files, outside string) string
	}{
		{"a link to the recorded folder", func(t *testing.T, files, outside string) string {
			linkFolder(t, outside, files)
			return outside
		}, nil},
		{"a record naming the files folder", func(t *testing.T, files, _ string) string {
			folder(t, files)
			return files
		}, nil},
		{"a recorded folder moved into the files folder", func(t *testing.T, files, outside string) string {
			folder(t, files)
			return outside
		}, nil},
		{"a recorded folder with no link", func(t *testing.T, _, outside string) string {
			folder(t, outside)
			return outside
		}, func(files, outside string) string {
			return "This Site data keeps its public files in " + outside + ". Link them, then run this again: " + linkCommand(outside, files, false)
		}},
		{"another folder in place of the link", func(t *testing.T, files, outside string) string {
			folder(t, files)
			folder(t, outside)
			return outside
		}, func(files, outside string) string {
			return "This Site data keeps its public files in " + outside + ", and " + files + " is another folder. Move that folder aside, then run: " + linkCommand(outside, files, false)
		}},
		{"a record naming Site data", func(_ *testing.T, files, _ string) string { return filepath.Dir(files) }, func(files, _ string) string {
			return overlappingRecord(filepath.Dir(files), files)
		}},
		{"a record naming a private folder", func(_ *testing.T, files, _ string) string {
			return filepath.Join(filepath.Dir(files), "private")
		}, func(files, _ string) string {
			return overlappingRecord(filepath.Join(filepath.Dir(files), "private"), files)
		}},
		{"neither folder", func(_ *testing.T, _, outside string) string { return outside }, func(files, outside string) string {
			return "This Site data keeps its public files in " + outside + ", which is gone. Restore that folder, or create " + files + "."
		}},
	} {
		t.Run(row.name, func(t *testing.T) {
			root := realDir(t)
			data := siteDataIn(t, root)
			files, outside := filepath.Join(data, "files"), filepath.Join(root, "public")
			recorded := row.prepare(t, files, outside)
			_, before := os.Lstat(files)
			_, _, err := siteDirs(data, recorded, false)
			if row.want == nil {
				if err != nil {
					t.Errorf("siteDirs refused: %v", err)
				}
				return
			}
			if want := row.want(files, outside); err == nil || err.Error() != want {
				t.Errorf("error = %v, want %q", err, want)
			}
			if _, after := os.Lstat(files); os.IsNotExist(before) != os.IsNotExist(after) {
				t.Error("the refusal changed the files folder")
			}
		})
	}
}

func TestLinkCommandQuotesEachPath(t *testing.T) {
	if got, want := linkCommand("/srv/my files", "/data/files", false), `ln -s '/srv/my files' /data/files`; got != want {
		t.Errorf("Unix = %s, want %s", got, want)
	}
	if got, want := linkCommand(`D:\my files`, `C:\data\files`, true), `mklink /J "C:\data\files" "D:\my files"`; got != want {
		t.Errorf("Windows = %s, want %s", got, want)
	}
}

func TestSiteStartRefusesFilesOverSiteDataBeforeRecordingThem(t *testing.T) {
	f := newSite(t, siteconfig.Site{Port: 7225})
	stub := filepath.Join(t.TempDir(), "runtime")
	if err := os.WriteFile(stub, []byte("#!/bin/sh\necho '{\"installed\": false}'\n"), 0o700); err != nil {
		t.Fatal(err)
	}
	l := f.launch()
	l.Entry = stub
	t.Chdir(f.cwd)
	linkFolder(t, f.data, filepath.Join(f.data, "files"))
	c := parseLine(t, false, "--foreground", "--host", "localhost", "--data-dir", f.data)
	if err := start(l, c); err == nil || err.Error() != overlapError(f.data, f.data) {
		t.Fatalf("start = %v", err)
	}
	if _, err := os.Stat(filepath.Join(f.data, "listener")); !os.IsNotExist(err) {
		t.Errorf("the refused start recorded a listener: %v", err)
	}
}

func TestSiteDrushRefusesARecordedFilesFolderWithoutItsLink(t *testing.T) {
	f := newSite(t, siteconfig.Site{Port: 7225})
	outside := realDir(t)
	f.record(t, `{"listen":"127.0.0.1:9000","host":"localhost","files-dir":"`+outside+`","scheme":"http"}`)
	_, err := drushPlan(f.launch(), parseDrush(t, false, "--data-dir", f.data, "status"), f.cwd)
	want := "This Site data keeps its public files in " + outside + ". Link them, then run this again: " + linkCommand(outside, filepath.Join(f.data, "files"), false)
	if err == nil || err.Error() != want {
		t.Errorf("error = %v, want %q", err, want)
	}
}

// The stub answers the check operation, so the site start reaches its brace
// refusals. Each Site data or files path holds a brace typed or behind a link.
func TestSiteStartRefusesBraces(t *testing.T) {
	f := newSite(t, siteconfig.Site{Port: 7225})
	stub := filepath.Join(t.TempDir(), "runtime")
	if err := os.WriteFile(stub, []byte("#!/bin/sh\necho '{\"installed\": false}'\n"), 0o700); err != nil {
		t.Fatal(err)
	}
	l := f.launch()
	l.Entry = stub
	braced := filepath.Join(realDir(t), "{client}")
	if err := os.MkdirAll(braced, 0o700); err != nil {
		t.Fatal(err)
	}
	linked := filepath.Join(f.cwd, "linked")
	if err := os.Symlink(braced, linked); err != nil {
		t.Fatal(err)
	}
	linkedFiles := siteDataIn(t, realDir(t))
	linkFolder(t, braced, filepath.Join(linkedFiles, "files"))
	t.Chdir(f.cwd)
	for _, row := range []struct {
		name string
		data string
		want string
	}{
		{"a typed --data-dir", braced, "--data-dir must not contain { or }: " + braced},
		{"a --data-dir link", "linked", "--data-dir must not contain { or }: " + braced},
		{"a files link", linkedFiles, "The Files directory must not contain { or }: " + braced},
	} {
		c := parseLine(t, false, "--foreground", "--host", "localhost", "--data-dir", row.data)
		if err := start(l, c); err == nil || err.Error() != row.want {
			t.Errorf("%s: start = %v, want %q", row.name, err, row.want)
		}
	}
	// A refused start writes no Site data.
	absent := filepath.Join(realDir(t), "open{dir")
	c := parseLine(t, false, "--foreground", "--host", "localhost", "--data-dir", absent)
	if err := start(l, c); err == nil {
		t.Error("start accepted the brace")
	}
	if _, err := os.Stat(absent); !os.IsNotExist(err) {
		t.Errorf("the refused start created %s: %v", absent, err)
	}
}
