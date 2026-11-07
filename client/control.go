package main

import (
	"context"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"os"
	"strings"
	"sync/atomic"
	"time"
)

// The Android app runs this agent as a child process and shows the player itself (a fullscreen WebView or
// another browser), so there is no Chromium with a DevTools port. The app answers on control_url instead:
//   GET capture?player=1 or capture?area=x,y,w,h (CSS pixels) &max=<longest edge>  -> JPEG of the player
//   POST restart  -> reload the player (a new WebView, or the browser opened again)
//   POST reboot   -> reboot when the device allows it, otherwise restart the app

// platformOS is the operating system reported to the server ("android" for the app's builds).
var platformOS = runtimeGOOS()

func (a *Agent) controlled() bool { return a.cfg.ControlURL != "" }

func (a *Agent) controlCall(ctx context.Context, method, path string) ([]byte, error) {
	ctx, cancel := context.WithTimeout(ctx, 30*time.Second)
	defer cancel()
	req, err := http.NewRequestWithContext(ctx, method, strings.TrimSuffix(a.cfg.ControlURL, "/")+"/"+path, nil)
	if err != nil {
		return nil, err
	}
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		return nil, fmt.Errorf("app: %w", err)
	}
	defer resp.Body.Close()
	body, err := io.ReadAll(io.LimitReader(resp.Body, 32<<20))
	if err != nil {
		return nil, fmt.Errorf("app: %w", err)
	}
	if resp.StatusCode != http.StatusOK {
		if len(body) > 300 {
			body = body[:300]
		}
		return nil, fmt.Errorf("app: HTTP %d %s", resp.StatusCode, strings.TrimSpace(string(body)))
	}
	return body, nil
}

// controlCapture asks the app for a JPEG of the player, the player area or an area of the page.
func (a *Agent) controlCapture(ctx context.Context, onlyPlayer bool, area []float64) ([]byte, error) {
	q := url.Values{"max": {fmt.Sprint(maxShotEdge)}}
	if len(area) == 4 {
		q.Set("area", fmt.Sprintf("%g,%g,%g,%g", area[0], area[1], area[2], area[3]))
	} else if onlyPlayer {
		q.Set("player", "1")
	}
	return a.controlCall(ctx, http.MethodGet, "capture?"+q.Encode())
}

// useDNSFile makes Go resolve names through the servers listed in path (one per line, kept up to date by the
// app as networks change), then public resolvers. Android has no /etc/resolv.conf for Go's own resolver.
func useDNSFile(path string) {
	if path == "" {
		return
	}
	var turn atomic.Uint32
	net.DefaultResolver = &net.Resolver{
		PreferGo: true,
		Dial: func(ctx context.Context, network, _ string) (net.Conn, error) {
			var servers []string
			if b, err := os.ReadFile(path); err == nil {
				for _, s := range strings.Fields(string(b)) {
					if ip := net.ParseIP(s); ip != nil {
						servers = append(servers, net.JoinHostPort(ip.String(), "53"))
					}
				}
			}
			servers = append(servers, "8.8.8.8:53", "1.1.1.1:53")
			// Each attempt of a lookup goes to the next server, so a dead one is skipped on the retry.
			s := servers[int(turn.Add(1)-1)%len(servers)]
			d := net.Dialer{Timeout: 3 * time.Second}
			return d.DialContext(ctx, network, s)
		},
	}
}
