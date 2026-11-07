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
		"os":               platformOS,
		"arch":             runtime.GOARCH,
		"go_version":       runtime.Version(),
		"local_ip":         localIP(),
		"agent_uptime_s":   int(time.Since(a.started).Seconds()),
		"playing_version":  a.st.Version,
		"last_error":       a.lastErr,
		"screenshot_error": a.shotErr,
		"update_error":     a.updErr,
		"playing_layout":   a.playing.Name,
		"cache_size":       a.cache.Size(),
	}
	a.mu.RUnlock()
	if free, ok := diskFree(a.cfg.DataDir); ok {
		info["disk_free"] = free
	}
	if up, ok := systemUptime(); ok {
		info["uptime_s"] = int(up.Seconds())
	}
	if t := a.playerLoaded.Load(); t > 0 {
		info["player_age_s"] = int(time.Since(time.Unix(0, t)).Seconds())
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
		return routedIP()
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
	return routedIP()
}

// routedIP is the address used to reach the internet, for systems that hide their interfaces from apps (Android).
// A UDP "connection" sends nothing.
func routedIP() string {
	c, err := net.Dial("udp", "8.8.8.8:53")
	if err != nil {
		return ""
	}
	defer c.Close()
	if a, ok := c.LocalAddr().(*net.UDPAddr); ok && !a.IP.IsLoopback() {
		return a.IP.String()
	}
	return ""
}
