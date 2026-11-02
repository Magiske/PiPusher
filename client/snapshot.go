package main

import (
	"context"
	"encoding/json"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"
)

// Snapshots of external web pages (URL media): while online, the player asks for a picture of the zone
// showing the page; offline it shows the latest picture instead of skipping the page. Kept per media id in
// <data dir>/snapshots/<id>.jpg, only for URL media in the current content.

const snapshotEvery = 10 * time.Minute // at most one snapshot per page in this time

func (a *Agent) snapshotDir() string { return filepath.Join(a.cfg.DataDir, "snapshots") }

func (a *Agent) snapshotPath(id int) string {
	return filepath.Join(a.snapshotDir(), strconv.Itoa(id)+".jpg")
}

// urlMedia returns the ids of the URL media in a configuration.
func urlMedia(config json.RawMessage) map[int]bool {
	var p Payload
	ids := map[int]bool{}
	if json.Unmarshal(config, &p) == nil {
		for _, m := range p.Media {
			if m.Type == "url" {
				ids[m.ID] = true
			}
		}
	}
	return ids
}

// snapshots lists the stored snapshots for the player: media id -> unix time taken (also a cache buster).
func (a *Agent) snapshots() map[string]int64 {
	out := map[string]int64{}
	entries, _ := os.ReadDir(a.snapshotDir())
	for _, e := range entries {
		id := strings.TrimSuffix(e.Name(), ".jpg")
		if info, err := e.Info(); err == nil && id != e.Name() {
			out[id] = info.ModTime().Unix()
		}
	}
	return out
}

// takeSnapshot captures area (CSS pixels of the player page) as the snapshot of URL media id.
func (a *Agent) takeSnapshot(ctx context.Context, id int, area []float64) {
	a.mu.RLock()
	wanted := a.st.Status == "ready" && urlMedia(a.st.Config)[id]
	a.mu.RUnlock()
	if st, err := os.Stat(a.snapshotPath(id)); !wanted || (err == nil && time.Since(st.ModTime()) < snapshotEvery) {
		return
	}
	if !a.shotMu.TryLock() {
		return // a screenshot is running; the player asks again later
	}
	defer a.shotMu.Unlock()
	ctx, cancel := context.WithTimeout(ctx, 60*time.Second)
	defer cancel()
	img, err := a.capture(ctx, false, area)
	if err != nil {
		log.Printf("snapshot of media %d failed: %v", id, err)
		return
	}
	if err := os.MkdirAll(a.snapshotDir(), 0o755); err != nil {
		log.Printf("snapshot: %v", err)
		return
	}
	if err := writeFileAtomic(a.snapshotPath(id), img, 0o644); err != nil {
		log.Printf("snapshot: %v", err)
		return
	}
	log.Printf("snapshot of media %d saved (%d bytes)", id, len(img))
}

// gcSnapshots removes snapshots of media that is no longer URL media in the configuration.
func (a *Agent) gcSnapshots(config json.RawMessage) {
	keep := urlMedia(config)
	entries, _ := os.ReadDir(a.snapshotDir())
	for _, e := range entries {
		id, err := strconv.Atoi(strings.TrimSuffix(e.Name(), ".jpg"))
		if err != nil || !keep[id] {
			os.Remove(filepath.Join(a.snapshotDir(), e.Name()))
		}
	}
}

func (a *Agent) handleSnapshot(w http.ResponseWriter, r *http.Request) {
	var req struct {
		Media int       `json:"media"`
		Area  []float64 `json:"area"` // x, y, w, h in CSS pixels
	}
	if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, 4096)).Decode(&req); err != nil || len(req.Area) != 4 {
		http.Error(w, "bad request", http.StatusBadRequest)
		return
	}
	go a.takeSnapshot(context.Background(), req.Media, req.Area)
	writeJSON(w, map[string]any{"ok": true})
}

func (a *Agent) serveSnapshot(w http.ResponseWriter, r *http.Request) {
	id, err := strconv.Atoi(strings.TrimSuffix(strings.TrimPrefix(r.URL.Path, "/snapshot/"), ".jpg"))
	if err != nil {
		http.NotFound(w, r)
		return
	}
	w.Header().Set("Cache-Control", "no-cache")
	http.ServeFile(w, r, a.snapshotPath(id))
}
