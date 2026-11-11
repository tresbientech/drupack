//go:build windows

package runtime

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"golang.org/x/sys/windows"
)

func withDACL(t *testing.T, dir, sddl string) {
	t.Helper()
	sd, err := windows.SecurityDescriptorFromString(sddl)
	if err != nil {
		t.Fatal(err)
	}
	dacl, _, err := sd.DACL()
	if err != nil {
		t.Fatal(err)
	}
	err = windows.SetNamedSecurityInfo(dir, windows.SE_FILE_OBJECT,
		windows.DACL_SECURITY_INFORMATION|windows.PROTECTED_DACL_SECURITY_INFORMATION, nil, nil, dacl, nil)
	if err != nil {
		t.Fatal(err)
	}
}

func createdRoot(t *testing.T) string {
	t.Helper()
	dir := filepath.Join(t.TempDir(), "parent", "root")
	if err := Create(dir); err != nil {
		t.Fatal(err)
	}
	return dir
}

// refusal is Exposure's answer for a cache root, nil when it passes.
func refusal(dir string) error {
	exposed, err := Exposure(dir, true)
	if exposed != nil {
		return exposed
	}
	return err
}

func currentUserSID(t *testing.T) string {
	t.Helper()
	current, err := windows.GetCurrentProcessToken().GetTokenUser()
	if err != nil {
		t.Fatal(err)
	}
	return current.User.Sid.String()
}

func TestCreateMakesARootExposureAccepts(t *testing.T) {
	dir := createdRoot(t)
	if err := refusal(dir); err != nil {
		t.Fatalf("a root Create made was refused: %v", err)
	}
}

func TestCreateProtectsTheRootFromItsParentsEntries(t *testing.T) {
	parent := filepath.Join(t.TempDir(), "parent")
	if err := os.Mkdir(parent, 0o700); err != nil {
		t.Fatal(err)
	}
	withDACL(t, parent, "D:P(A;OICI;FA;;;"+currentUserSID(t)+")(A;OICI;0x1301bf;;;BU)")
	dir := filepath.Join(parent, "root")
	if err := Create(dir); err != nil {
		t.Fatal(err)
	}
	sd, err := windows.GetNamedSecurityInfo(dir, windows.SE_FILE_OBJECT, windows.DACL_SECURITY_INFORMATION)
	if err != nil {
		t.Fatal(err)
	}
	if sddl := sd.String(); strings.Contains(sddl, ";BU)") || !strings.Contains(sddl, "D:P") {
		t.Fatalf("the created root's ACL is %s, want a protected one with no entry for Users", sddl)
	}
}

func TestCreateLeavesAnExistingDirectoryAlone(t *testing.T) {
	dir := createdRoot(t)
	withDACL(t, dir, "D:P(A;OICI;FA;;;"+currentUserSID(t)+")(A;OICI;0x1301bf;;;BU)")
	if err := Create(dir); err != nil {
		t.Fatal(err)
	}
	sd, err := windows.GetNamedSecurityInfo(dir, windows.SE_FILE_OBJECT, windows.DACL_SECURITY_INFORMATION)
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(sd.String(), ";BU)") {
		t.Fatalf("Create rewrote the ACL of a directory it did not create: %s", sd.String())
	}
}
