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

func TestCreateLeavesAnExistingDirectoryAlone(t *testing.T) {
	dir := createdRoot(t)
	withDACL(t, dir, "D:P(A;OICI;FA;;;"+currentUserSID(t)+")(A;OICI;0x1301bf;;;BU)")
	if err := Create(dir); err != nil {
		t.Fatal(err)
	}
	if err := refusal(dir); err == nil {
		t.Fatal("Create rewrote the ACL of a directory it did not create")
	}
}

func TestExposureRefusesARootOtherAccountsCanWrite(t *testing.T) {
	user := currentUserSID(t)
	for name, entry := range map[string]string{
		"modify":         "(A;OICI;0x1301bf;;;BU)",
		"inherit only":   "(A;OICIIO;FA;;;BU)",
		"generic write":  "(A;;GW;;;WD)",
		"delete a child": "(A;;0x40;;;AU)",
	} {
		t.Run(name, func(t *testing.T) {
			dir := createdRoot(t)
			withDACL(t, dir, "D:P(A;OICI;FA;;;"+user+")"+entry)
			err := refusal(dir)
			if err == nil || !strings.Contains(err.Error(), "is open to") {
				t.Fatalf("Exposure returned %v for an entry %s, want a refusal naming the writer", err, entry)
			}
		})
	}
}

func TestExposureAcceptsReadAndDenyEntries(t *testing.T) {
	dir := createdRoot(t)
	withDACL(t, dir, "D:P(D;OICI;FA;;;BU)(A;OICI;FA;;;"+currentUserSID(t)+")(A;OICI;FRFX;;;BU)")
	if err := refusal(dir); err != nil {
		t.Fatalf("a root other accounts can only read was refused: %v", err)
	}
}

func TestExposureAcceptsTheDACLPythonsMkdtempSets(t *testing.T) {
	dir := createdRoot(t)
	withDACL(t, dir, "D:P(A;OICI;FA;;;SY)(A;OICI;FA;;;BA)(A;OICI;FA;;;OW)")
	if err := refusal(dir); err != nil {
		t.Fatalf("a root granting SYSTEM, Administrators and OWNER RIGHTS was refused: %v", err)
	}
}

func TestExposureRefusesANullDACL(t *testing.T) {
	dir := createdRoot(t)
	err := windows.SetNamedSecurityInfo(dir, windows.SE_FILE_OBJECT,
		windows.DACL_SECURITY_INFORMATION|windows.PROTECTED_DACL_SECURITY_INFORMATION, nil, nil, nil, nil)
	if err != nil {
		t.Fatal(err)
	}
	if err := refusal(dir); err == nil || !strings.Contains(err.Error(), "Everyone") {
		t.Fatalf("Exposure returned %v for a NULL DACL, want a refusal naming Everyone", err)
	}
}

func TestExposureRefusesAParentAnotherAccountCanDeleteFrom(t *testing.T) {
	dir := createdRoot(t)
	withDACL(t, filepath.Dir(dir), "D:P(A;OICI;FA;;;"+currentUserSID(t)+")(A;;0x40;;;AU)")
	exposed, err := Exposure(dir, true)
	if err != nil || exposed == nil || exposed.Leaf || exposed.Folder != filepath.Dir(dir) {
		t.Fatalf("Exposure returned %+v, %v for a parent granting FILE_DELETE_CHILD, want the parent named", exposed, err)
	}
}

func TestExposureAcceptsAParentWhereOthersOnlyCreateFolders(t *testing.T) {
	dir := createdRoot(t)
	withDACL(t, filepath.Dir(dir), "D:P(A;OICI;FA;;;"+currentUserSID(t)+")(A;;0x4;;;AU)(A;OICIIO;0x1301bf;;;AU)")
	if err := refusal(dir); err != nil {
		t.Fatalf("a parent where others create folders, and modify only what they create, was refused: %v", err)
	}
}

func TestTheSystemDriveRootPassesAsAParent(t *testing.T) {
	drive := filepath.VolumeName(os.Getenv("SystemRoot")) + `\`
	exposed, err := ancestor(drive, filepath.Join(drive, "Users"))
	if err != nil || exposed != nil {
		t.Fatalf("%s as the parent of Users: %+v, %v", drive, exposed, err)
	}
}
