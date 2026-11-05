//go:build !windows

package main

import (
	"bytes"
	"context"
	"os"
	"path/filepath"
	"strconv"
	"syscall"
)

// killKioskBrowser ends the kiosk browser: the Chromium process started with --remote-debugging-port=<port>
// (its helper processes exit with it). The kiosk script or DietPi's autostart starts it again. Returns how many
// processes were ended; 0 when none was found (no /proc on macOS).
func killKioskBrowser(_ context.Context, port string) (int, error) {
	flag := []byte("--remote-debugging-port=" + port)
	dirs, _ := filepath.Glob("/proc/[0-9]*")
	n := 0
	for _, d := range dirs {
		pid, err := strconv.Atoi(filepath.Base(d))
		if err != nil || pid == os.Getpid() {
			continue
		}
		b, err := os.ReadFile(filepath.Join(d, "cmdline"))
		if err != nil {
			continue
		}
		var match, helper bool
		for _, arg := range bytes.Split(b, []byte{0}) {
			match = match || bytes.Equal(arg, flag)
			helper = helper || bytes.HasPrefix(arg, []byte("--type="))
		}
		if match && !helper && syscall.Kill(pid, syscall.SIGKILL) == nil {
			n++
		}
	}
	return n, nil
}
