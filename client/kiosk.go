package main

import (
	"context"
	"log"
	"net/http"
	"strings"
	"time"
)

// playerQuiet is how long the player page may go without asking for /api/state (it does so every
// 5 s) before the kiosk browser counts as stuck. Also the grace period after start and after a fix.
const playerQuiet = 45 * time.Second

// watchKiosk keeps the kiosk browser on a working player. On slow boards (Pi Zero 2 W) Chromium sometimes
// never finishes loading its first tab and shows a blank white page while a new tab loads fine, and when
// the browser starts before the agent it shows "This site can't be reached" without retrying. Both
// tabs may already carry the player's URL, so the test is whether the player page is polling the agent:
// when it has been quiet for two checks in a row, open the player in a new tab and close the old player
// and blank tabs. Does nothing while the browser (DevTools) is not reachable.
func (a *Agent) watchKiosk(ctx context.Context) {
	player := "http://" + a.cfg.Listen + "/"
	if strings.HasPrefix(a.cfg.Listen, ":") {
		player = "http://127.0.0.1" + a.cfg.Listen + "/"
	}
	fixed := a.started
	stuck := 0
	for {
		select {
		case <-ctx.Done():
			return
		case <-time.After(15 * time.Second):
		}
		seen := time.Unix(0, a.playerSeen.Load())
		if seen.Before(fixed) {
			seen = fixed
		}
		if time.Since(seen) < playerQuiet {
			stuck = 0
			continue
		}
		var targets []devtoolsTarget
		if err := a.devtoolsCall(ctx, http.MethodGet, "/json/list", &targets); err != nil {
			stuck = 0
			continue
		}
		var old []string
		for _, t := range targets {
			if t.Type != "page" {
				continue
			}
			if strings.Contains(t.URL, a.cfg.Listen) || t.URL == "" || t.URL == "about:blank" ||
				strings.HasPrefix(t.URL, "chrome-error:") {
				old = append(old, t.ID)
			}
		}
		if len(old) == 0 {
			stuck = 0
			continue
		}
		if stuck++; stuck < 2 {
			continue
		}
		stuck = 0
		fixed = time.Now()
		log.Printf("kiosk player has not polled for %s, opening the player in a new tab", time.Since(seen).Round(time.Second))
		if err := a.devtoolsCall(ctx, http.MethodPut, "/json/new?"+player, nil); err != nil {
			log.Printf("kiosk: %v", err)
			continue
		}
		for _, id := range old {
			_ = a.devtoolsCall(ctx, http.MethodGet, "/json/close/"+id, nil)
		}
	}
}
