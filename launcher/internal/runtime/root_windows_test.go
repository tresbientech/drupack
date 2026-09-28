//go:build windows

package runtime

import (
	"path/filepath"
	"strings"
	"testing"

	"golang.org/x/sys/windows"
)

// withDACL replaces dir's DACL with the one sddl describes, blocking inheritance.
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

// createdRoot is a cache root makeRoot created, as a start creates one.
func createdRoot(t *testing.T) string {
	t.Helper()
	dir := filepath.Join(t.TempDir(), "parent", "root")
	if err := makeRoot(dir); err != nil {
		t.Fatal(err)
	}
	return dir
}

func currentUserSID(t *testing.T) string {
	t.Helper()
	current, err := windows.GetCurrentProcessToken().GetTokenUser()
	if err != nil {
		t.Fatal(err)
	}
	return current.User.Sid.String()
}

func TestMakeRootCreatesARootPrivateRootAccepts(t *testing.T) {
	dir := createdRoot(t)
	if _, err := privateRoot(dir); err != nil {
		t.Fatalf("a root makeRoot created was refused: %v", err)
	}
}

func TestMakeRootLeavesAnExistingDirectoryAlone(t *testing.T) {
	dir := createdRoot(t)
	withDACL(t, dir, "D:P(A;OICI;FA;;;"+currentUserSID(t)+")(A;OICI;0x1301bf;;;BU)")
	if err := makeRoot(dir); err != nil {
		t.Fatal(err)
	}
	if _, err := privateRoot(dir); err == nil {
		t.Fatal("makeRoot rewrote the ACL of a directory it did not create")
	}
}

func TestPrivateRootRefusesARootOtherAccountsCanWrite(t *testing.T) {
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
			_, err := privateRoot(dir)
			if err == nil || !strings.Contains(err.Error(), "write to it") {
				t.Fatalf("privateRoot returned %v for an entry %s, want a refusal naming the writer", err, entry)
			}
		})
	}
}

func TestPrivateRootAcceptsReadAndDenyEntries(t *testing.T) {
	dir := createdRoot(t)
	withDACL(t, dir, "D:P(D;OICI;FA;;;BU)(A;OICI;FA;;;"+currentUserSID(t)+")(A;OICI;FRFX;;;BU)")
	if _, err := privateRoot(dir); err != nil {
		t.Fatalf("a root other accounts can only read was refused: %v", err)
	}
}

func TestPrivateRootAcceptsTheDACLPythonsMkdtempSets(t *testing.T) {
	dir := createdRoot(t)
	withDACL(t, dir, "D:P(A;OICI;FA;;;SY)(A;OICI;FA;;;BA)(A;OICI;FA;;;OW)")
	if _, err := privateRoot(dir); err != nil {
		t.Fatalf("a root granting SYSTEM, Administrators and OWNER RIGHTS was refused: %v", err)
	}
}

func TestPrivateRootRefusesANullDACL(t *testing.T) {
	dir := createdRoot(t)
	err := windows.SetNamedSecurityInfo(dir, windows.SE_FILE_OBJECT,
		windows.DACL_SECURITY_INFORMATION|windows.PROTECTED_DACL_SECURITY_INFORMATION, nil, nil, nil, nil)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := privateRoot(dir); err == nil || !strings.Contains(err.Error(), "Everyone") {
		t.Fatalf("privateRoot returned %v for a NULL DACL, want a refusal naming Everyone", err)
	}
}
