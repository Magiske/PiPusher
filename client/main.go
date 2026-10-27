// PiPusher client: syncs layouts and media from a PiPusher server, caches them on disk and
// serves a local player page (shown full screen by Chromium in kiosk mode).
// If the server cannot be reached the player keeps running from the cache.
package main

import (
	"context"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"log"
	"net/http"
	"os"
	"os/signal"
	"path/filepath"
	"runtime"
	"strings"
	"syscall"
	"time"
)

// version is set at build time with -ldflags "-X main.version=..."
var version = "dev"

type Config struct {
	ServerURL       string   `json:"server_url"`       // e.g. https://example.com/pipusher/
	RegistrationKey string   `json:"registration_key"` // optional: auto-approve on the server
	Name            string   `json:"name"`             // optional: initial screen name (defaults to hostname)
	DataDir         string   `json:"data_dir"`         // cache and identity storage
	Listen          string   `json:"listen"`           // local player address
	PollInterval    int      `json:"poll_interval"`    // seconds; the server value takes precedence
	InsecureTLS     bool     `json:"insecure_tls"`     // accept self-signed server certificates
	RebootCommand   []string `json:"reboot_command"`   // command run for the "reboot" remote command
	DevToolsURL     string   `json:"devtools_url"`     // kiosk Chromium's remote debugging endpoint (screenshots)
}

func defaultConfigPath() string {
	if runtime.GOOS == "linux" {
		return "/etc/pipusher/pipusher.json"
	}
	return "pipusher.json"
}

func loadConfig(path string) (Config, error) {
	cfg := Config{Listen: "127.0.0.1:8090", PollInterval: 60}
	b, err := os.ReadFile(path)
	switch {
	case err == nil:
		if err := json.Unmarshal(b, &cfg); err != nil {
			return cfg, fmt.Errorf("parse %s: %w", path, err)
		}
	case errors.Is(err, os.ErrNotExist):
		log.Printf("config %s not found, using defaults", path)
	default:
		return cfg, err
	}
	if cfg.DataDir == "" {
		if runtime.GOOS == "linux" {
			cfg.DataDir = "/var/lib/pipusher"
		} else {
			cfg.DataDir = filepath.Join(filepath.Dir(path), "pipusher-data")
		}
	}
	if cfg.Listen == "" {
		cfg.Listen = "127.0.0.1:8090"
	}
	if cfg.DevToolsURL == "" {
		cfg.DevToolsURL = "http://127.0.0.1:9222"
	}
	if len(cfg.RebootCommand) == 0 {
		cfg.RebootCommand = []string{"sudo", "-n", "/sbin/reboot"}
	}
	return cfg, nil
}

func normaliseServerURL(u string) string {
	u = strings.TrimSpace(u)
	if u == "" {
		return ""
	}
	if !strings.Contains(u, "://") {
		u = "https://" + u
	}
	u = strings.TrimSuffix(u, "index.php")
	if !strings.HasSuffix(u, "/") {
		u += "/"
	}
	return u
}

// openLogFile appends to path, first moving it to path.old once it exceeds 5 MB.
func openLogFile(path string) (*os.File, error) {
	if st, err := os.Stat(path); err == nil && st.Size() > 5<<20 {
		_ = os.Rename(path, path+".old")
	}
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		return nil, err
	}
	return os.OpenFile(path, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o644)
}

func main() {
	cfgPath := flag.String("config", defaultConfigPath(), "path to the configuration file")
	serverFlag := flag.String("server", "", "server URL (overrides the config file)")
	listenFlag := flag.String("listen", "", "local player listen address (overrides the config file)")
	dataFlag := flag.String("data", "", "data directory (overrides the config file)")
	logFlag := flag.String("log", "", "write the log to this file instead of stderr (used on Windows)")
	showVersion := flag.Bool("version", false, "print the version and exit")
	flag.Parse()

	if *showVersion {
		fmt.Println("pipusher", version, runtime.GOOS+"/"+runtime.GOARCH)
		return
	}
	log.SetFlags(log.LstdFlags | log.Lmsgprefix)
	log.SetPrefix("pipusher: ")
	if *logFlag != "" {
		f, err := openLogFile(*logFlag)
		if err != nil {
			log.Fatal(err)
		}
		defer f.Close()
		log.SetOutput(f)
	}

	cfg, err := loadConfig(*cfgPath)
	if err != nil {
		log.Fatal(err)
	}
	if *serverFlag != "" {
		cfg.ServerURL = *serverFlag
	}
	if *listenFlag != "" {
		cfg.Listen = *listenFlag
	}
	if *dataFlag != "" {
		cfg.DataDir = *dataFlag
	}
	cfg.ServerURL = normaliseServerURL(cfg.ServerURL)

	agent, err := NewAgent(cfg)
	if err != nil {
		log.Fatal(err)
	}
	log.Printf("version %s, server %q, data %s, player http://%s/", version, cfg.ServerURL, cfg.DataDir, cfg.Listen)

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	srv := &http.Server{Addr: cfg.Listen, Handler: agent.Routes(), ReadHeaderTimeout: 10 * time.Second}
	go func() {
		if err := srv.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			log.Fatalf("player server: %v", err)
		}
	}()
	go agent.Run(ctx)

	<-ctx.Done()
	log.Print("shutting down")
	shutdownCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	_ = srv.Shutdown(shutdownCtx)
}
