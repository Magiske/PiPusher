package main

import (
	"context"
	"fmt"
	"os/exec"
	"strings"
	"syscall"
)

// killKioskBrowser ends the kiosk browser: the chrome.exe / msedge.exe started with
// --remote-debugging-port=<port>, with its helper processes. pipusher-kiosk.ps1 starts it again.
// Returns how many browsers were ended. Get-WmiObject also works on Windows 7's PowerShell 2.
func killKioskBrowser(ctx context.Context, port string) (int, error) {
	script := `Get-WmiObject Win32_Process -Filter "Name='chrome.exe' OR Name='msedge.exe'" | ` +
		`Where-Object { $_.CommandLine -like '*--remote-debugging-port=` + port + `*' -and $_.CommandLine -notlike '*--type=*' } | ` +
		`ForEach-Object { $_.ProcessId }`
	out, err := hidden(exec.CommandContext(ctx, "powershell.exe", "-NoProfile", "-NonInteractive", "-Command", script)).Output()
	if err != nil {
		return 0, fmt.Errorf("listing browser processes: %w", err)
	}
	n := 0
	for _, pid := range strings.Fields(string(out)) {
		if hidden(exec.CommandContext(ctx, "taskkill.exe", "/F", "/T", "/PID", pid)).Run() == nil {
			n++
		}
	}
	return n, nil
}

// hidden keeps a console window from flashing up over the player.
func hidden(c *exec.Cmd) *exec.Cmd {
	c.SysProcAttr = &syscall.SysProcAttr{HideWindow: true, CreationFlags: 0x08000000} // CREATE_NO_WINDOW
	return c
}
