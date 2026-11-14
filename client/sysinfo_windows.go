package main

import (
	"syscall"
	"unsafe"
)

var (
	getDiskFreeSpaceEx   = syscall.NewLazyDLL("kernel32.dll").NewProc("GetDiskFreeSpaceExW")
	globalMemoryStatusEx = syscall.NewLazyDLL("kernel32.dll").NewProc("GlobalMemoryStatusEx")
)

// diskSpace returns the space free for the client and the size of the disk holding path.
func diskSpace(path string) (free, total uint64, ok bool) {
	p, err := syscall.UTF16PtrFromString(path)
	if err != nil || getDiskFreeSpaceEx.Find() != nil {
		return 0, 0, false
	}
	var avail, size, totalFree uint64
	r, _, _ := getDiskFreeSpaceEx.Call(uintptr(unsafe.Pointer(p)), uintptr(unsafe.Pointer(&avail)),
		uintptr(unsafe.Pointer(&size)), uintptr(unsafe.Pointer(&totalFree)))
	if r == 0 {
		return 0, 0, false
	}
	return avail, size, true
}

type memoryStatusEx struct {
	length               uint32
	memoryLoad           uint32
	totalPhys            uint64
	availPhys            uint64
	totalPageFile        uint64
	availPageFile        uint64
	totalVirtual         uint64
	availVirtual         uint64
	availExtendedVirtual uint64
}

// memoryInfo returns the total and available physical memory.
func memoryInfo() (total, avail uint64, ok bool) {
	if globalMemoryStatusEx.Find() != nil {
		return 0, 0, false
	}
	var m memoryStatusEx
	m.length = uint32(unsafe.Sizeof(m))
	if r, _, _ := globalMemoryStatusEx.Call(uintptr(unsafe.Pointer(&m))); r == 0 {
		return 0, 0, false
	}
	return m.totalPhys, m.availPhys, true
}
