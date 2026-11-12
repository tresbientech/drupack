//go:build windows

package state

import (
	"unsafe"

	"golang.org/x/sys/windows"
)

// replaceFile renames from over to. os.Rename asks MoveFileEx to replace, which
// fails with "Access is denied" while any handle has the old file open, even a
// handle that shares deletion. A rename with POSIX semantics unlinks the old
// file at once, so a reader that opened it with FILE_SHARE_DELETE keeps reading
// it and the new file takes the name.
func replaceFile(from, to string) error {
	name, err := windows.UTF16FromString(to)
	if err != nil {
		return err
	}
	source, err := windows.UTF16PtrFromString(from)
	if err != nil {
		return err
	}
	handle, err := windows.CreateFile(source, windows.DELETE|windows.SYNCHRONIZE,
		windows.FILE_SHARE_READ|windows.FILE_SHARE_WRITE|windows.FILE_SHARE_DELETE, nil, windows.OPEN_EXISTING, windows.FILE_ATTRIBUTE_NORMAL, 0)
	if err != nil {
		return err
	}
	defer windows.CloseHandle(handle)
	// FILE_RENAME_INFO_EX: a flags word, the root directory handle, the name's
	// byte length without its NUL and the name.
	type renameInfo struct {
		Flags          uint32
		RootDirectory  windows.Handle
		FileNameLength uint32
		FileName       [1]uint16
	}
	buffer := make([]byte, int(unsafe.Offsetof(renameInfo{}.FileName))+len(name)*2)
	info := (*renameInfo)(unsafe.Pointer(&buffer[0]))
	info.Flags = windows.FILE_RENAME_REPLACE_IF_EXISTS | windows.FILE_RENAME_POSIX_SEMANTICS
	info.FileNameLength = uint32((len(name) - 1) * 2)
	copy(unsafe.Slice(&info.FileName[0], len(name)), name)
	return windows.SetFileInformationByHandle(handle, windows.FileRenameInfoEx, &buffer[0], uint32(len(buffer)))
}
