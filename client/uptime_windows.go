package main

import (
	"syscall"
	"time"
)

var getTickCount64 = syscall.NewLazyDLL("kernel32.dll").NewProc("GetTickCount64")

// systemUptime is the time since Windows started (GetTickCount64, Vista and newer).
func systemUptime() (time.Duration, bool) {
	if getTickCount64.Find() != nil {
		return 0, false
	}
	ms, _, _ := getTickCount64.Call()
	return time.Duration(ms) * time.Millisecond, true
}
