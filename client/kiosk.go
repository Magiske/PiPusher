package main

import (
	"context"
	"log"
	"net/http"
	"strings"
	"time"
)

// watchKiosk keeps the kiosk browser on the player. On slow boards (Pi Zero 2 W) Chromium sometimes
// never finishes loading its first tab and shows a blank white page while a new tab loads fine. When the
// browser has no player page but a blank one for two checks in a row, open the player in a new tab and
// close the blank ones. Does nothing while the browser (DevTools) is not reachable.
func (a *Agent) watchKiosk(ctx context.Context) {
	player := "http://" + a.cfg.Listen + "/"
	if strings.HasPrefix(a.cfg.Listen, ":") {
		player = "http://127.0.0.1" + a.cfg.Listen + "/"
	}
	stuck := 0
	for {
		select {
		case <-ctx.Done():
			return
		case <-time.After(15 * time.Second):
		}
		var targets []devtoolsTarget
		if err := a.devtoolsCall(ctx, http.MethodGet, "/json/list", &targets); err != nil {
			stuck = 0
			continue
		}
		var blank []string
		onPlayer := false
		for _, t := range targets {
			if t.Type != "page" {
				continue
			}
			if strings.Contains(t.URL, a.cfg.Listen) {
				onPlayer = true
			} else if t.URL == "" || t.URL == "about:blank" {
				blank = append(blank, t.ID)
			}
		}
		if onPlayer || len(blank) == 0 {
			stuck = 0
			continue
		}
		if stuck++; stuck < 2 {
			continue
		}
		stuck = 0
		log.Printf("kiosk browser is stuck on a blank page, opening the player in a new tab")
		if err := a.devtoolsCall(ctx, http.MethodPut, "/json/new?"+player, nil); err != nil {
			log.Printf("kiosk: %v", err)
			continue
		}
		for _, id := range blank {
			_ = a.devtoolsCall(ctx, http.MethodGet, "/json/close/"+id, nil)
		}
	}
}
