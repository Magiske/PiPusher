package main

import (
	"archive/tar"
	"archive/zip"
	"bufio"
	"compress/gzip"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net/http"
	"net/url"
	"os"
	"os/exec"
	"path"
	"path/filepath"
	"runtime"
	"strings"
	"time"
)

// Remote client update (the "update" command): the agent downloads the server's client package,
// takes its own binary out of it, checks that the new binary runs, puts it in place of the running
// one and exits, so systemd (Linux) or the kiosk loop (Windows) starts the new version.

func (a *Agent) selfUpdate(ctx context.Context) {
	if !a.updMu.TryLock() {
		return // one at a time
	}
	defer a.updMu.Unlock()
	err := a.doUpdate(ctx)
	a.mu.Lock()
	if err != nil {
		a.updErr = err.Error()
		log.Printf("client update failed: %v", err)
	} else {
		a.updErr = ""
	}
	a.mu.Unlock()
}

func (a *Agent) doUpdate(ctx context.Context) error {
	if a.controlled() {
		return errors.New("Android: install the new PiPusher app (APK) on the device")
	}
	ctx, cancel := context.WithTimeout(ctx, 15*time.Minute)
	defer cancel()
	u := fmt.Sprintf("%sapi.php?action=client&os=%s&arch=%s&go=%s", a.cfg.ServerURL,
		runtime.GOOS, runtime.GOARCH, url.QueryEscape(runtime.Version()))
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
		msg, _ := io.ReadAll(io.LimitReader(resp.Body, 512))
		return fmt.Errorf("download: HTTP %d %s", resp.StatusCode, strings.TrimSpace(string(msg)))
	}
	newVersion := resp.Header.Get("X-PiPusher-Version")
	if newVersion == version {
		log.Printf("client update: already version %s", version)
		return nil
	}
	log.Printf("client update: %s -> %s", version, newVersion)

	dir := filepath.Join(a.cfg.DataDir, "update")
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return err
	}
	name := "pipusher"
	if runtime.GOOS == "windows" {
		name = "pipusher.exe"
	}
	bin := filepath.Join(dir, name)
	if err := extractBinary(bufio.NewReader(io.LimitReader(resp.Body, 300<<20)), updateMember(), bin); err != nil {
		return err
	}
	// The new binary must run on this device and be the version the server announced.
	out, err := exec.CommandContext(ctx, bin, "-version").CombinedOutput()
	if err != nil || !strings.Contains(string(out), " "+newVersion+" ") {
		os.Remove(bin)
		return fmt.Errorf("new client does not run here (%v): %s", err, strings.TrimSpace(string(out)))
	}
	backup, err := a.replaceExecutable(ctx, bin)
	if err != nil {
		return err
	}
	// Until the new version has synced once, the old one is kept so it can come back (see checkUpdate).
	if err := writeJSONAtomic(updateGuardPath(a.cfg.DataDir), updateGuard{Backup: backup, From: version, To: newVersion}, 0o644); err != nil {
		log.Printf("client update: cannot write the rollback guard: %v", err)
	}
	log.Printf("client update: version %s installed, restarting", newVersion)
	go func() {
		time.Sleep(time.Second)
		close(a.restart)
	}()
	return nil
}

// updateMember is the file name of this device's binary in the client package.
func updateMember() string {
	switch {
	case runtime.GOOS == "windows" && runtime.GOARCH == "386":
		return "pipusher-386.exe"
	case runtime.GOOS == "windows":
		return "pipusher.exe"
	case runtime.GOARCH == "arm":
		return "pipusher-linux-armv7"
	}
	return "pipusher-linux-" + runtime.GOARCH
}

// extractBinary copies the member named name from a .tar.gz or .zip package to dst.
func extractBinary(r *bufio.Reader, name, dst string) error {
	magic, err := r.Peek(2)
	if err != nil {
		return fmt.Errorf("reading the package: %w", err)
	}
	if string(magic) == "PK" {
		// zip needs random access, so keep a copy of the package first.
		tmp := dst + ".zip"
		defer os.Remove(tmp)
		if err := writeFile(tmp, r, 0o644); err != nil {
			return err
		}
		zr, err := zip.OpenReader(tmp)
		if err != nil {
			return err
		}
		defer zr.Close()
		for _, f := range zr.File {
			if path.Base(f.Name) == name {
				rc, err := f.Open()
				if err != nil {
					return err
				}
				defer rc.Close()
				return writeFile(dst, rc, 0o755)
			}
		}
		return fmt.Errorf("%s is not in the client package", name)
	}
	gz, err := gzip.NewReader(r)
	if err != nil {
		return fmt.Errorf("the client package is neither .zip nor .tar.gz: %w", err)
	}
	tr := tar.NewReader(gz)
	for {
		h, err := tr.Next()
		if errors.Is(err, io.EOF) {
			return fmt.Errorf("%s is not in the client package", name)
		}
		if err != nil {
			return err
		}
		if h.Typeflag == tar.TypeReg && path.Base(strings.ReplaceAll(h.Name, `\`, "/")) == name {
			return writeFile(dst, tr, 0o755)
		}
	}
}

func writeFile(dst string, r io.Reader, mode os.FileMode) error {
	f, err := os.OpenFile(dst, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, mode)
	if err != nil {
		return err
	}
	if _, err := io.Copy(f, r); err != nil {
		f.Close()
		return err
	}
	if err := f.Close(); err != nil {
		return err
	}
	return os.Chmod(dst, mode)
}

func currentExecutable() (string, error) {
	exe, err := os.Executable()
	if err != nil {
		return "", err
	}
	if p, err := filepath.EvalSymlinks(exe); err == nil {
		exe = p
	}
	return exe, nil
}

// replaceExecutable puts bin in place of the running binary and returns where the old one was kept.
// A running binary can be renamed (also on Windows), so it is moved aside to .old.
// /usr/local/bin belongs to root: there install.sh allows exactly the sudo install command below,
// and the old binary is copied to the data directory first.
func (a *Agent) replaceExecutable(ctx context.Context, bin string) (string, error) {
	exe, err := currentExecutable()
	if err != nil {
		return "", err
	}
	old := exe + ".old"
	os.Remove(old)
	if err := os.Rename(exe, old); err == nil {
		if err := moveFile(bin, exe); err != nil {
			os.Rename(old, exe)
			return "", err
		}
		return old, nil
	} else if runtime.GOOS == "windows" {
		return "", err
	}
	prev := filepath.Join(filepath.Dir(bin), "pipusher.prev")
	if err := copyFile(exe, prev); err != nil {
		return "", fmt.Errorf("keeping a copy of the running client: %w", err)
	}
	if err := sudoInstall(ctx, bin, exe); err != nil {
		os.Remove(prev)
		return "", err
	}
	os.Remove(bin)
	return prev, nil
}

func sudoInstall(ctx context.Context, bin, exe string) error {
	out, err := exec.CommandContext(ctx, "sudo", "-n", "/usr/bin/install", "-m", "0755", bin, exe).CombinedOutput()
	if err != nil {
		return fmt.Errorf("replacing %s: %v %s (install the client once more with install.sh to allow remote updates)",
			exe, err, strings.TrimSpace(string(out)))
	}
	return nil
}

func copyFile(from, to string) error {
	f, err := os.Open(from)
	if err != nil {
		return err
	}
	defer f.Close()
	return writeFile(to, f, 0o755)
}

func moveFile(from, to string) error {
	if err := os.Rename(from, to); err == nil {
		return nil
	}
	f, err := os.Open(from)
	if err != nil {
		return err
	}
	defer f.Close()
	if err := writeFile(to, f, 0o755); err != nil {
		return err
	}
	f.Close()
	return os.Remove(from)
}

// ------------------------------------------------------------------ rollback of a bad update

// updateGuard (update/pending.json) is written when a new version is put in place and removed after
// its first successful sync. A new version that keeps stopping before that is swapped back for Backup.
type updateGuard struct {
	Backup    string    `json:"backup"`
	From      string    `json:"from"`
	To        string    `json:"to"`
	Starts    int       `json:"starts"`
	LastStart time.Time `json:"last_start"`
}

const (
	rollbackStarts = 3               // starts allowed without a successful sync
	rollbackWindow = 5 * time.Minute // starts further apart than this are not a crash loop
)

func updateGuardPath(dataDir string) string { return filepath.Join(dataDir, "update", "pending.json") }
func rollbackNotePath(dataDir string) string { return filepath.Join(dataDir, "update", "rolled-back.txt") }

// checkUpdate runs first at every start. It returns true when this start comes after a new version
// was installed and has not synced yet. A version that has started too often in a row without syncing
// is replaced by the previous one and the process exits, so systemd or the kiosk loop starts the old version.
func checkUpdate(dataDir string) bool {
	exe, _ := currentExecutable()
	os.Remove(exe + ".bad")
	var g updateGuard
	b, err := os.ReadFile(updateGuardPath(dataDir))
	if err != nil || json.Unmarshal(b, &g) != nil || g.To != version {
		// No update pending, or this is the previous version again: nothing to watch.
		os.Remove(updateGuardPath(dataDir))
		if exe != "" && err != nil {
			os.Remove(exe + ".old")
		}
		return false
	}
	if time.Since(g.LastStart) > rollbackWindow {
		g.Starts = 0
	}
	g.Starts++
	g.LastStart = time.Now()
	if g.Starts <= rollbackStarts {
		if err := writeJSONAtomic(updateGuardPath(dataDir), g, 0o644); err != nil {
			log.Printf("client update: %v", err)
		}
		log.Printf("client update: version %s start %d, waiting for the first sync", version, g.Starts)
		return true
	}
	log.Printf("client update: version %s stopped %d times before its first sync, going back to %s", version, g.Starts-1, g.From)
	if err := rollback(dataDir, exe, g.Backup); err != nil {
		log.Printf("client update: rollback failed: %v", err)
		return true // keep running this version rather than nothing
	}
	note := fmt.Sprintf("version %s stopped %d times before it could sync, so version %s was put back", version, g.Starts-1, g.From)
	_ = os.WriteFile(rollbackNotePath(dataDir), []byte(note), 0o644)
	os.Remove(updateGuardPath(dataDir))
	os.Exit(1)
	return false
}

func rollback(dataDir, exe, backup string) error {
	if exe == "" || backup == "" {
		return errors.New("no previous version kept")
	}
	if _, err := os.Stat(backup); err != nil {
		return err
	}
	if backup == exe+".old" {
		if err := os.Rename(exe, exe+".bad"); err != nil {
			return err
		}
		if err := os.Rename(backup, exe); err != nil {
			os.Rename(exe+".bad", exe)
			return err
		}
		return nil
	}
	// Root-owned binary: the sudoers rule only allows installing from update/pipusher.
	bin := filepath.Join(dataDir, "update", "pipusher")
	if err := copyFile(backup, bin); err != nil {
		return err
	}
	ctx, cancel := context.WithTimeout(context.Background(), time.Minute)
	defer cancel()
	if err := sudoInstall(ctx, bin, exe); err != nil {
		return err
	}
	os.Remove(bin)
	os.Remove(backup)
	return nil
}

// commitUpdate is called after the first successful sync of a new version: the old one is not needed any more.
func commitUpdate(dataDir string) {
	var g updateGuard
	if b, err := os.ReadFile(updateGuardPath(dataDir)); err == nil && json.Unmarshal(b, &g) == nil && g.Backup != "" {
		os.Remove(g.Backup)
	}
	os.Remove(updateGuardPath(dataDir))
	log.Printf("client update: version %s synced, previous version removed", version)
}

// takeRollbackNote returns (once) the message left by a rollback, for the screen page.
func takeRollbackNote(dataDir string) string {
	b, err := os.ReadFile(rollbackNotePath(dataDir))
	if err != nil {
		return ""
	}
	os.Remove(rollbackNotePath(dataDir))
	return strings.TrimSpace(string(b))
}
