package main

/*
#include <stdlib.h>
*/
import "C"

import (
	"fmt"
	"syscall"
	"unsafe"
)

// setCRTEnv sets name in the C runtime's table, and an empty value removes it.
// PHP's getenv reads that table, which os.Setenv leaves unchanged on Windows. PHP
// and this file link the same static C runtime, so the call goes through cgo:
// ucrtbase.dll holds another table.
func setCRTEnv(name, value string) error {
	wideName, err := syscall.UTF16PtrFromString(name)
	if err != nil {
		return err
	}
	wideValue, err := syscall.UTF16PtrFromString(value)
	if err != nil {
		return err
	}
	if errno := C._wputenv_s((*C.wchar_t)(unsafe.Pointer(wideName)), (*C.wchar_t)(unsafe.Pointer(wideValue))); errno != 0 {
		return fmt.Errorf("_wputenv_s %s: errno %d", name, errno)
	}
	return nil
}
