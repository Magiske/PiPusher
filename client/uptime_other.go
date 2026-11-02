//go:build !windows

package main

import (
	"os"
	"strconv"
	"strings"
	"time"
)

// systemUptime is the time since the system booted (/proc/uptime; not available on macOS).
func systemUptime() (time.Duration, bool) {
	b, err := os.ReadFile("/proc/uptime")
	if err != nil {
		return 0, false
	}
	f := strings.Fields(string(b))
	if len(f) == 0 {
		return 0, false
	}
	v, err := strconv.ParseFloat(f[0], 64)
	if err != nil {
		return 0, false
	}
	return time.Duration(v * float64(time.Second)), true
}
