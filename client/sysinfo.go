package main

import (
	"net"
	"os"
	"runtime"
	"strconv"
	"strings"
	"time"
)

// systemInfo is reported to the server on every sync and shown in the admin panel.
func (a *Agent) systemInfo() map[string]any {
	a.mu.RLock()
	info := map[string]any{
		"os":               runtime.GOOS,
		"arch":             runtime.GOARCH,
		"local_ip":         localIP(),
		"agent_uptime_s":   int(time.Since(a.started).Seconds()),
		"playing_version":  a.st.Version,
		"last_error":       a.lastErr,
		"screenshot_error": a.shotErr,
		"playing_layout":   a.playing.Name,
		"cache_size":       a.cache.Size(),
	}
	a.mu.RUnlock()
	if free, ok := diskFree(a.cfg.DataDir); ok {
		info["disk_free"] = free
	}
	if b, err := os.ReadFile("/proc/uptime"); err == nil {
		if f := strings.Fields(string(b)); len(f) > 0 {
			if v, err := strconv.ParseFloat(f[0], 64); err == nil {
				info["uptime_s"] = int(v)
			}
		}
	}
	if b, err := os.ReadFile("/sys/class/thermal/thermal_zone0/temp"); err == nil {
		if v, err := strconv.Atoi(strings.TrimSpace(string(b))); err == nil {
			info["cpu_temp_c"] = float64(v/100) / 10
		}
	}
	if b, err := os.ReadFile("/proc/device-tree/model"); err == nil {
		info["model"] = strings.TrimRight(string(b), "\x00\n")
	}
	return info
}

// localIP returns the first non-loopback IPv4 address, for display on the pairing screen.
func localIP() string {
	ifaces, err := net.Interfaces()
	if err != nil {
		return ""
	}
	for _, ifc := range ifaces {
		if ifc.Flags&net.FlagUp == 0 || ifc.Flags&net.FlagLoopback != 0 {
			continue
		}
		addrs, _ := ifc.Addrs()
		for _, addr := range addrs {
			if ipn, ok := addr.(*net.IPNet); ok && ipn.IP.To4() != nil && !ipn.IP.IsLinkLocalUnicast() {
				return ipn.IP.String()
			}
		}
	}
	return ""
}
