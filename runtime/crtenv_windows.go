package main

import (
	"fmt"
	"syscall"
	"unsafe"
)

// ucrtPutenv is the C runtime's _wputenv_s. PHP's getenv reads the C runtime's own
// table, which os.Setenv leaves unchanged on Windows. php8ts.dll links ucrtbase.dll
// at load time, so the name resolves to the module already in the process.
var ucrtPutenv = syscall.NewLazyDLL("ucrtbase.dll").NewProc("_wputenv_s")

// setCRTEnv sets name in the C runtime's table, and an empty value removes it.
func setCRTEnv(name, value string) error {
	wideName, err := syscall.UTF16PtrFromString(name)
	if err != nil {
		return err
	}
	wideValue, err := syscall.UTF16PtrFromString(value)
	if err != nil {
		return err
	}
	if errno, _, _ := ucrtPutenv.Call(uintptr(unsafe.Pointer(wideName)), uintptr(unsafe.Pointer(wideValue))); errno != 0 {
		return fmt.Errorf("_wputenv_s %s: errno %d", name, errno)
	}
	return nil
}
