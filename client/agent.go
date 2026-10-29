package main

import (
	"bytes"
	"context"
	"crypto/rand"
	"crypto/tls"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"sync"
	"sync/atomic"
	"time"
)

type Identity struct {
	UUID   string `json:"uuid"`
	Secret string `json:"secret"`
}

type MediaFile struct {
	Path   string `json:"path"`
	Size   int64  `json:"size"`
	SHA256 string `json:"sha256"`
}

type Media struct {
	ID    int         `json:"id"`
	Name  string      `json:"name"`
	Type  string      `json:"type"` // html, image, video, url
	Entry string      `json:"entry"`
	URL   string      `json:"url"`
	Files []MediaFile `json:"files"`
}

// Payload is the part of the server's configuration the agent needs; the player reads the rest
// (layouts, playlist sequence and rules) itself.
type Payload struct {
	Media map[string]Media `json:"media"`
}

// Playing is reported by the player whenever it switches layout.
type Playing struct {
	ID          int    `json:"id"`
	Name        string `json:"name"`
	Orientation string `json:"orientation"`
}

// persisted is what survives restarts: the last content the server gave us (cached mode).
type persisted struct {
	Status  string          `json:"status"` // ready, pending, nolayout
	Code    string          `json:"code"`
	Name    string          `json:"name"`
	Version string          `json:"version"`
	Config  json.RawMessage `json:"config"`
	Muted   bool            `json:"muted"` // sound off (set in the admin panel)
	SavedAt time.Time       `json:"saved_at"`
}

type syncResponse struct {
	Status       string          `json:"status"`
	Code         string          `json:"code"`
	Name         string          `json:"name"`
	PollInterval int             `json:"poll_interval"`
	ShotInterval int             `json:"screenshot_interval"`
	Commands     []string        `json:"commands"`
	Muted        bool            `json:"muted"`
	Version      string          `json:"version"`
	Config       json.RawMessage `json:"config"`
	Error        string          `json:"error"`
}

type Agent struct {
	cfg     Config
	id      Identity
	http    *http.Client
	cache   *Cache
	started time.Time
	wake    chan struct{}

	mu           sync.RWMutex
	st           persisted
	lastOK       time.Time
	lastErr      string
	reload       int
	interval     time.Duration
	shotInterval time.Duration // 0 = screenshots only on demand
	lastShot     time.Time
	shotErr      string
	shotMu       sync.Mutex // held while a screenshot is in progress
	updErr       string
	updMu        sync.Mutex    // held while a client update is in progress
	restart      chan struct{} // closed after a client update: main exits so the new version starts
	playing      Playing
	playerSeen   atomic.Int64 // unix nanoseconds of the player page's last /api/state request
}

func NewAgent(cfg Config) (*Agent, error) {
	if err := os.MkdirAll(cfg.DataDir, 0o755); err != nil {
		return nil, err
	}
	a := &Agent{
		cfg:      cfg,
		started:  time.Now(),
		wake:     make(chan struct{}, 1),
		restart:  make(chan struct{}),
		interval: time.Duration(maxInt(10, cfg.PollInterval)) * time.Second,
		cache:    NewCache(cfg.DataDir),
	}
	tr := http.DefaultTransport.(*http.Transport).Clone()
	tr.ResponseHeaderTimeout = 60 * time.Second
	if cfg.InsecureTLS {
		tr.TLSClientConfig = &tls.Config{InsecureSkipVerify: true}
	}
	a.http = &http.Client{Transport: tr}
	if err := a.loadIdentity(); err != nil {
		return nil, err
	}
	a.loadState()
	return a, nil
}

func (a *Agent) path(name string) string { return filepath.Join(a.cfg.DataDir, name) }

func randomHex(n int) string {
	b := make([]byte, n)
	if _, err := rand.Read(b); err != nil {
		panic(err)
	}
	return hex.EncodeToString(b)
}

func (a *Agent) loadIdentity() error {
	b, err := os.ReadFile(a.path("identity.json"))
	if err == nil && json.Unmarshal(b, &a.id) == nil && a.id.UUID != "" && a.id.Secret != "" {
		return nil
	}
	u := randomHex(16)
	a.id = Identity{
		UUID:   fmt.Sprintf("%s-%s-%s-%s-%s", u[0:8], u[8:12], u[12:16], u[16:20], u[20:32]),
		Secret: randomHex(24),
	}
	log.Printf("generated new device identity %s", a.id.UUID)
	return writeJSONAtomic(a.path("identity.json"), a.id, 0o600)
}

func (a *Agent) loadState() {
	b, err := os.ReadFile(a.path("state.json"))
	if err != nil {
		return
	}
	if err := json.Unmarshal(b, &a.st); err != nil {
		log.Printf("ignoring corrupt state.json: %v", err)
		a.st = persisted{}
		return
	}
	log.Printf("loaded cached content (status %s, version %.10s, saved %s)", a.st.Status, a.st.Version, a.st.SavedAt.Format(time.RFC3339))
}

func (a *Agent) saveState() error {
	a.mu.RLock()
	st := a.st
	a.mu.RUnlock()
	st.SavedAt = time.Now()
	return writeJSONAtomic(a.path("state.json"), st, 0o644)
}

func writeJSONAtomic(path string, v any, mode os.FileMode) error {
	b, err := json.MarshalIndent(v, "", "  ")
	if err != nil {
		return err
	}
	tmp := path + ".tmp"
	if err := os.WriteFile(tmp, b, mode); err != nil {
		return err
	}
	return os.Rename(tmp, path)
}

func (a *Agent) authHeader() string { return a.id.UUID + ":" + a.id.Secret }

// Run syncs with the server until ctx is cancelled.
func (a *Agent) Run(ctx context.Context) {
	for {
		err := a.syncOnce(ctx)
		a.mu.Lock()
		if err != nil {
			a.lastErr = err.Error()
			log.Printf("sync failed: %v", err)
		} else {
			a.lastErr = ""
			a.lastOK = time.Now()
		}
		wait := a.interval
		// Periodic screenshot; give the kiosk browser a minute to start after boot.
		dueShot := err == nil && a.shotInterval > 0 && time.Since(a.started) > time.Minute &&
			time.Since(a.lastShot) >= a.shotInterval
		a.mu.Unlock()
		if dueShot {
			go a.takeScreenshot(ctx)
		}
		if err != nil && wait > 30*time.Second {
			wait = 30 * time.Second // retry sooner while the server is unreachable
		}
		select {
		case <-ctx.Done():
			return
		case <-time.After(wait):
		case <-a.wake:
		}
	}
}

func (a *Agent) TriggerSync() {
	select {
	case a.wake <- struct{}{}:
	default:
	}
}

func (a *Agent) syncOnce(ctx context.Context) error {
	if a.cfg.ServerURL == "" {
		return errors.New("server_url is not configured")
	}
	a.mu.RLock()
	current := a.st.Version
	a.mu.RUnlock()
	hostname, _ := os.Hostname()
	body, _ := json.Marshal(map[string]any{
		"name":             a.cfg.Name,
		"hostname":         hostname,
		"client_version":   version,
		"config_version":   current,
		"registration_key": a.cfg.RegistrationKey,
		"info":             a.systemInfo(),
	})

	reqCtx, cancel := context.WithTimeout(ctx, 30*time.Second)
	defer cancel()
	req, err := http.NewRequestWithContext(reqCtx, http.MethodPost, a.cfg.ServerURL+"api.php?action=sync", bytes.NewReader(body))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-Device-Auth", a.authHeader())
	req.Header.Set("User-Agent", "PiPusher/"+version)
	resp, err := a.http.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	raw, err := io.ReadAll(io.LimitReader(resp.Body, 32<<20))
	if err != nil {
		return err
	}
	var sr syncResponse
	if err := json.Unmarshal(raw, &sr); err != nil {
		return fmt.Errorf("server returned HTTP %d with invalid JSON (is server_url correct?): %.120s", resp.StatusCode, raw)
	}
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("server returned HTTP %d: %s", resp.StatusCode, sr.Error)
	}

	a.mu.Lock()
	if sr.PollInterval > 0 {
		a.interval = time.Duration(maxInt(10, sr.PollInterval)) * time.Second
	}
	a.shotInterval = time.Duration(maxInt(0, sr.ShotInterval)) * time.Second
	changed := a.st.Code != sr.Code || a.st.Name != sr.Name || a.st.Muted != sr.Muted
	a.st.Muted = sr.Muted
	a.st.Code, a.st.Name = sr.Code, sr.Name
	a.mu.Unlock()

	switch sr.Status {
	case "ready":
		if len(sr.Config) > 0 && sr.Version != current {
			if err := a.activate(ctx, sr.Version, sr.Config); err != nil {
				return err
			}
			changed = false // activate already saved
		} else {
			a.mu.Lock()
			if a.st.Status != "ready" && a.st.Version == sr.Version && a.st.Config != nil {
				a.st.Status = "ready"
				changed = true
			}
			a.mu.Unlock()
		}
	case "pending", "nolayout":
		a.mu.Lock()
		if a.st.Status != sr.Status {
			a.st.Status = sr.Status
			changed = true
		}
		a.mu.Unlock()
	default:
		return fmt.Errorf("unexpected status %q from server", sr.Status)
	}
	if changed {
		if err := a.saveState(); err != nil {
			log.Printf("save state: %v", err)
		}
	}
	for _, cmd := range sr.Commands {
		a.runCommand(ctx, cmd)
	}
	return nil
}

// activate downloads everything a new configuration needs and only then switches to it,
// so the screen keeps showing the old content until the new content is complete.
func (a *Agent) activate(ctx context.Context, version string, config json.RawMessage) error {
	var p Payload
	if err := json.Unmarshal(config, &p); err != nil {
		return fmt.Errorf("invalid config from server: %w", err)
	}
	log.Printf("new content version %.10s: %d media item(s)", version, len(p.Media))
	if err := a.cache.Ensure(ctx, p.Media, a.download); err != nil {
		return fmt.Errorf("downloading content: %w", err)
	}
	a.mu.Lock()
	a.st.Status = "ready"
	a.st.Version = version
	a.st.Config = config
	a.mu.Unlock()
	if err := a.saveState(); err != nil {
		return err
	}
	a.cache.GC(p.Media)
	log.Printf("content version %.10s is now playing", version)
	return nil
}

func (a *Agent) download(ctx context.Context, mediaID int, rel string, w io.Writer) error {
	ctx, cancel := context.WithTimeout(ctx, 30*time.Minute)
	defer cancel()
	u := fmt.Sprintf("%sapi.php?action=file&media=%d&path=%s", a.cfg.ServerURL, mediaID, queryEscape(rel))
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, u, nil)
	if err != nil {
		return err
	}
	req.Header.Set("X-Device-Auth", a.authHeader())
	req.Header.Set("User-Agent", "PiPusher/"+version)
	resp, err := a.http.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("GET media %d/%s: HTTP %d", mediaID, rel, resp.StatusCode)
	}
	_, err = io.Copy(w, resp.Body)
	return err
}

func (a *Agent) runCommand(ctx context.Context, cmd string) {
	log.Printf("remote command: %s", cmd)
	switch cmd {
	case "screenshot":
		go a.takeScreenshot(ctx)
	case "reload":
		a.mu.Lock()
		a.reload++
		a.mu.Unlock()
	case "sync":
		a.TriggerSync()
	case "update":
		go a.selfUpdate(ctx)
	case "reboot":
		go func() {
			time.Sleep(2 * time.Second)
			c := a.cfg.RebootCommand
			if out, err := exec.Command(c[0], c[1:]...).CombinedOutput(); err != nil {
				log.Printf("reboot failed: %v %s", err, out)
			}
		}()
	default:
		log.Printf("unknown command %q ignored", cmd)
	}
}

// PlayerState is what the local player page polls.
type PlayerState struct {
	State        string          `json:"state"` // ready, pending, nolayout, waiting
	Code         string          `json:"code,omitempty"`
	Name         string          `json:"name,omitempty"`
	Online       bool            `json:"online"`
	Version      string          `json:"version,omitempty"`
	Reload       int             `json:"reload"`
	Muted        bool            `json:"muted"`
	Server       string          `json:"server,omitempty"`
	IP           string          `json:"ip,omitempty"`
	AgentVersion string          `json:"agent_version"`
	Message      string          `json:"message,omitempty"`
	LastSync     *time.Time      `json:"last_sync,omitempty"`
	Config       json.RawMessage `json:"config,omitempty"`
}

func (a *Agent) online() bool {
	return !a.lastOK.IsZero() && time.Since(a.lastOK) < 3*a.interval+15*time.Second
}

func (a *Agent) PlayerState() PlayerState {
	a.mu.RLock()
	defer a.mu.RUnlock()
	ps := PlayerState{
		Code:         a.st.Code,
		Name:         a.st.Name,
		Online:       a.online(),
		Reload:       a.reload,
		Muted:        a.st.Muted,
		Server:       a.cfg.ServerURL,
		IP:           localIP(),
		AgentVersion: version,
	}
	if !a.lastOK.IsZero() {
		t := a.lastOK
		ps.LastSync = &t
	}
	switch {
	case a.st.Status == "ready" && len(a.st.Config) > 0:
		ps.State = "ready"
		ps.Version = a.st.Version
		ps.Config = a.st.Config
	case a.st.Status == "pending" || a.st.Status == "nolayout":
		ps.State = a.st.Status
	default:
		ps.State = "waiting"
		ps.Message = a.lastErr
		if ps.Message == "" {
			ps.Message = "Contacting " + a.cfg.ServerURL
		}
	}
	return ps
}
