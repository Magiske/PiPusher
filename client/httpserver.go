package main

import (
	"embed"
	"encoding/json"
	"io/fs"
	"mime"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"time"
)

//go:embed web
var webFiles embed.FS

func init() {
	// Go's built-in MIME table lacks some media types; minimal systems may lack /etc/mime.types.
	for ext, typ := range map[string]string{
		".mp4": "video/mp4", ".m4v": "video/mp4", ".webm": "video/webm", ".mov": "video/quicktime",
		".ogv": "video/ogg", ".mp3": "audio/mpeg", ".ogg": "audio/ogg", ".wav": "audio/wav",
		".woff": "font/woff", ".woff2": "font/woff2", ".ttf": "font/ttf", ".otf": "font/otf",
		".bmp": "image/bmp", ".ico": "image/x-icon", ".csv": "text/csv", ".txt": "text/plain; charset=utf-8",
	} {
		_ = mime.AddExtensionType(ext, typ)
	}
}

func (a *Agent) Routes() http.Handler {
	web, _ := fs.Sub(webFiles, "web")
	static := http.FileServer(http.FS(web))
	mux := http.NewServeMux()
	mux.Handle("/", onlyMethod("GET", noCache(static)))
	mux.Handle("/api/state", onlyMethod("GET", http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		a.playerSeen.Store(time.Now().UnixNano())
		writeJSON(w, a.PlayerState())
	})))
	mux.Handle("/api/health", onlyMethod("GET", http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, map[string]any{"ok": true, "version": version})
	})))
	mux.Handle("/api/sync", onlyMethod("POST", http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		a.TriggerSync()
		writeJSON(w, map[string]any{"ok": true})
	})))
	mux.Handle("/api/playing", onlyMethod("POST", http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var p Playing
		if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, 4096)).Decode(&p); err != nil {
			http.Error(w, "bad request", http.StatusBadRequest)
			return
		}
		a.mu.Lock()
		a.playing = p
		a.mu.Unlock()
		writeJSON(w, map[string]any{"ok": true})
	})))
	mux.Handle("/media/", onlyMethod("GET", http.HandlerFunc(a.serveMedia)))
	return mux
}

// GET /media/{id}/{path...}
func (a *Agent) serveMedia(w http.ResponseWriter, r *http.Request) {
	id, rel, ok := strings.Cut(strings.TrimPrefix(r.URL.Path, "/media/"), "/")
	if !ok {
		http.NotFound(w, r)
		return
	}
	p, err := a.cache.LocalPath(id, rel)
	if err != nil {
		http.NotFound(w, r)
		return
	}
	f, err := os.Open(p)
	if err != nil {
		http.NotFound(w, r)
		return
	}
	defer f.Close()
	st, err := f.Stat()
	if err != nil || st.IsDir() {
		http.NotFound(w, r)
		return
	}
	w.Header().Set("Cache-Control", "no-cache")
	http.ServeContent(w, r, filepath.Base(p), st.ModTime(), f) // handles Range requests for video
}

// onlyMethod allows one method (GET also allows HEAD), like "GET /path" patterns in Go 1.22+.
func onlyMethod(method string, h http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != method && !(method == "GET" && r.Method == "HEAD") {
			w.Header().Set("Allow", method)
			http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
			return
		}
		h.ServeHTTP(w, r)
	})
}

func noCache(h http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Cache-Control", "no-cache")
		h.ServeHTTP(w, r)
	})
}

func writeJSON(w http.ResponseWriter, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("Cache-Control", "no-store")
	_ = json.NewEncoder(w).Encode(v)
}
