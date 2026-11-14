package main

import (
	"bufio"
	"os"
	"strconv"
	"strings"
	"syscall"
)

// diskSpace returns the space free for the client and the size of the disk holding path.
func diskSpace(path string) (free, total uint64, ok bool) {
	var st syscall.Statfs_t
	if err := syscall.Statfs(path, &st); err != nil {
		return 0, 0, false
	}
	return uint64(st.Bavail) * uint64(st.Bsize), uint64(st.Blocks) * uint64(st.Bsize), true
}

// memoryInfo reads the total and available memory from /proc/meminfo (also on Android).
func memoryInfo() (total, avail uint64, ok bool) {
	f, err := os.Open("/proc/meminfo")
	if err != nil {
		return 0, 0, false
	}
	defer f.Close()
	var free, buffers, cached uint64
	hasAvail := false
	sc := bufio.NewScanner(f)
	for sc.Scan() {
		fields := strings.Fields(sc.Text())
		if len(fields) < 2 {
			continue
		}
		v, err := strconv.ParseUint(fields[1], 10, 64)
		if err != nil {
			continue
		}
		v *= 1024 // kB
		switch fields[0] {
		case "MemTotal:":
			total = v
		case "MemAvailable:":
			avail, hasAvail = v, true
		case "MemFree:":
			free = v
		case "Buffers:":
			buffers = v
		case "Cached:":
			cached = v
		}
	}
	if !hasAvail { // kernels before 3.14
		avail = free + buffers + cached
	}
	return total, avail, total > 0
}
