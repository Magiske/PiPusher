package main

import (
	"archive/tar"
	"archive/zip"
	"bufio"
	"compress/gzip"
	"context"
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
	if err := a.replaceExecutable(ctx, bin); err != nil {
		return err
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

// replaceExecutable puts bin in place of the running binary. A running binary can be renamed
// (also on Windows), so it is moved aside to .old; that file is removed at the next start.
// /usr/local/bin belongs to root: there install.sh allows exactly the sudo install command below.
func (a *Agent) replaceExecutable(ctx context.Context, bin string) error {
	exe, err := os.Executable()
	if err != nil {
		return err
	}
	if p, err := filepath.EvalSymlinks(exe); err == nil {
		exe = p
	}
	old := exe + ".old"
	os.Remove(old)
	if err := os.Rename(exe, old); err == nil {
		if err := moveFile(bin, exe); err != nil {
			os.Rename(old, exe)
			return err
		}
		return nil
	} else if runtime.GOOS == "windows" {
		return err
	}
	out, err := exec.CommandContext(ctx, "sudo", "-n", "/usr/bin/install", "-m", "0755", bin, exe).CombinedOutput()
	if err != nil {
		return fmt.Errorf("replacing %s: %v %s (install the client once more with install.sh to allow remote updates)",
			exe, err, strings.TrimSpace(string(out)))
	}
	os.Remove(bin)
	return nil
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

// removeOldExecutable cleans up after an update (the previous binary, moved aside).
func removeOldExecutable() {
	if exe, err := os.Executable(); err == nil {
		if p, err := filepath.EvalSymlinks(exe); err == nil {
			exe = p
		}
		os.Remove(exe + ".old")
	}
}
