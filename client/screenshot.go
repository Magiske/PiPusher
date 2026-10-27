package main

import (
	"bufio"
	"bytes"
	"context"
	"crypto/rand"
	"encoding/base64"
	"encoding/binary"
	"encoding/json"
	"errors"
	"fmt"
	"image"
	"image/jpeg"
	"io"
	"log"
	"math"
	"net"
	"net/http"
	"net/url"
	"strings"
	"time"
)

// Screenshots are taken through the Chrome DevTools Protocol: the kiosk starts Chromium with
// --remote-debugging-port (bound to localhost), which works the same under Wayland and X11 and
// captures exactly what the player renders.

const maxShotEdge = 1280 // longest edge of uploaded screenshots, in pixels

func (a *Agent) takeScreenshot(ctx context.Context) {
	if !a.shotMu.TryLock() {
		return // one at a time
	}
	defer a.shotMu.Unlock()
	a.mu.Lock()
	a.lastShot = time.Now()
	a.mu.Unlock()

	err := a.captureAndUpload(ctx)
	a.mu.Lock()
	if err != nil {
		a.shotErr = err.Error()
		log.Printf("screenshot failed: %v", err)
	} else {
		a.shotErr = ""
	}
	a.mu.Unlock()
}

func (a *Agent) captureAndUpload(ctx context.Context) error {
	ctx, cancel := context.WithTimeout(ctx, 60*time.Second)
	defer cancel()
	img, err := a.capture(ctx)
	if err != nil {
		return err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, a.cfg.ServerURL+"api.php?action=screenshot", bytes.NewReader(img))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "image/jpeg")
	req.Header.Set("X-Device-Auth", a.authHeader())
	req.Header.Set("User-Agent", "PiPusher/"+version)
	resp, err := a.http.Do(req)
	if err != nil {
		return fmt.Errorf("upload: %w", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		body, _ := io.ReadAll(io.LimitReader(resp.Body, 300))
		return fmt.Errorf("upload: HTTP %d %s", resp.StatusCode, body)
	}
	log.Printf("screenshot uploaded (%d bytes)", len(img))
	return nil
}

// capture returns a JPEG of the player page, scaled down and turned upright for portrait layouts.
func (a *Agent) capture(ctx context.Context) ([]byte, error) {
	wsURL, err := a.findPlayerTarget(ctx)
	if err != nil {
		return nil, err
	}
	conn, err := dialWebSocket(ctx, wsURL)
	if err != nil {
		return nil, fmt.Errorf("devtools: %w", err)
	}
	defer conn.Close()

	var metrics struct {
		Viewport struct {
			W float64 `json:"clientWidth"`
			H float64 `json:"clientHeight"`
		} `json:"cssLayoutViewport"`
	}
	if err := conn.call("Page.getLayoutMetrics", nil, &metrics); err != nil {
		return nil, err
	}
	w, h := metrics.Viewport.W, metrics.Viewport.H
	if w <= 0 || h <= 0 {
		return nil, errors.New("devtools: page has no size")
	}
	scale := math.Min(1.0, maxShotEdge/math.Max(w, h))
	var shot struct {
		Data string `json:"data"`
	}
	params := map[string]any{
		"format":  "jpeg",
		"quality": 75,
		"clip":    map[string]any{"x": 0, "y": 0, "width": w, "height": h, "scale": scale},
	}
	if err := conn.call("Page.captureScreenshot", params, &shot); err != nil {
		return nil, err
	}
	data, err := base64.StdEncoding.DecodeString(shot.Data)
	if err != nil {
		return nil, err
	}
	if rot := a.currentRotation(); rot != 0 {
		return rotateJPEG(data, rot)
	}
	return data, nil
}

// findPlayerTarget asks Chromium for its open pages and picks the PiPusher player.
func (a *Agent) findPlayerTarget(ctx context.Context) (string, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, strings.TrimSuffix(a.cfg.DevToolsURL, "/")+"/json/list", nil)
	if err != nil {
		return "", err
	}
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		return "", fmt.Errorf("kiosk browser not reachable on %s (is pipusher-kiosk running?)", a.cfg.DevToolsURL)
	}
	defer resp.Body.Close()
	var targets []struct {
		Type  string `json:"type"`
		URL   string `json:"url"`
		WSURL string `json:"webSocketDebuggerUrl"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&targets); err != nil {
		return "", fmt.Errorf("devtools target list: %w", err)
	}
	fallback := ""
	for _, t := range targets {
		if t.Type != "page" || t.WSURL == "" {
			continue
		}
		if strings.Contains(t.URL, a.cfg.Listen) {
			return t.WSURL, nil
		}
		if fallback == "" {
			fallback = t.WSURL
		}
	}
	if fallback == "" {
		return "", errors.New("no browser page found")
	}
	return fallback, nil
}

// currentRotation returns the clockwise rotation the player applies for the layout on screen.
func (a *Agent) currentRotation() int {
	a.mu.RLock()
	defer a.mu.RUnlock()
	if a.st.Status != "ready" {
		return 0
	}
	return map[string]int{"portrait": 90, "landscape-flipped": 180, "portrait-flipped": 270}[a.playing.Orientation]
}

// rotateJPEG undoes the player's clockwise rotation so the screenshot shows the layout upright.
func rotateJPEG(data []byte, cwDegrees int) ([]byte, error) {
	src, err := jpeg.Decode(bytes.NewReader(data))
	if err != nil {
		return nil, err
	}
	b := src.Bounds()
	w, h := b.Dx(), b.Dy()
	var dst *image.RGBA
	switch cwDegrees {
	case 90: // rotate counter-clockwise 90°
		dst = image.NewRGBA(image.Rect(0, 0, h, w))
		for y := 0; y < w; y++ {
			for x := 0; x < h; x++ {
				dst.Set(x, y, src.At(b.Min.X+w-1-y, b.Min.Y+x))
			}
		}
	case 180:
		dst = image.NewRGBA(image.Rect(0, 0, w, h))
		for y := 0; y < h; y++ {
			for x := 0; x < w; x++ {
				dst.Set(x, y, src.At(b.Min.X+w-1-x, b.Min.Y+h-1-y))
			}
		}
	case 270: // rotate clockwise 90°
		dst = image.NewRGBA(image.Rect(0, 0, h, w))
		for y := 0; y < w; y++ {
			for x := 0; x < h; x++ {
				dst.Set(x, y, src.At(b.Min.X+y, b.Min.Y+h-1-x))
			}
		}
	default:
		return data, nil
	}
	var out bytes.Buffer
	if err := jpeg.Encode(&out, dst, &jpeg.Options{Quality: 80}); err != nil {
		return nil, err
	}
	return out.Bytes(), nil
}

// ---------------------------------------------------------------- minimal WebSocket client (RFC 6455)

type wsConn struct {
	c  net.Conn
	br *bufio.Reader
	id int
}

func dialWebSocket(ctx context.Context, rawURL string) (*wsConn, error) {
	u, err := url.Parse(rawURL)
	if err != nil {
		return nil, err
	}
	var d net.Dialer
	c, err := d.DialContext(ctx, "tcp", u.Host)
	if err != nil {
		return nil, err
	}
	if dl, ok := ctx.Deadline(); ok {
		_ = c.SetDeadline(dl)
	}
	key := make([]byte, 16)
	_, _ = rand.Read(key)
	fmt.Fprintf(c, "GET %s HTTP/1.1\r\nHost: %s\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"+
		"Sec-WebSocket-Key: %s\r\nSec-WebSocket-Version: 13\r\n\r\n", u.RequestURI(), u.Host, base64.StdEncoding.EncodeToString(key))
	br := bufio.NewReader(c)
	resp, err := http.ReadResponse(br, nil)
	if err != nil {
		c.Close()
		return nil, err
	}
	resp.Body.Close()
	if resp.StatusCode != http.StatusSwitchingProtocols {
		c.Close()
		return nil, fmt.Errorf("websocket handshake: HTTP %d", resp.StatusCode)
	}
	return &wsConn{c: c, br: br}, nil
}

func (w *wsConn) Close() error { return w.c.Close() }

func (w *wsConn) writeFrame(opcode byte, payload []byte) error {
	hdr := []byte{0x80 | opcode}
	n := len(payload)
	switch {
	case n < 126:
		hdr = append(hdr, 0x80|byte(n))
	case n < 1<<16:
		hdr = append(hdr, 0x80|126, byte(n>>8), byte(n))
	default:
		hdr = append(hdr, 0x80|127)
		hdr = binary.BigEndian.AppendUint64(hdr, uint64(n))
	}
	mask := make([]byte, 4)
	_, _ = rand.Read(mask)
	hdr = append(hdr, mask...)
	masked := make([]byte, n)
	for i := range payload {
		masked[i] = payload[i] ^ mask[i%4]
	}
	_, err := w.c.Write(append(hdr, masked...))
	return err
}

// readMessage returns the next complete text/binary message, answering pings on the way.
func (w *wsConn) readMessage() ([]byte, error) {
	var msg []byte
	for {
		var h [2]byte
		if _, err := io.ReadFull(w.br, h[:]); err != nil {
			return nil, err
		}
		fin, opcode := h[0]&0x80 != 0, h[0]&0x0f
		n := uint64(h[1] & 0x7f)
		switch n {
		case 126:
			var ext [2]byte
			if _, err := io.ReadFull(w.br, ext[:]); err != nil {
				return nil, err
			}
			n = uint64(binary.BigEndian.Uint16(ext[:]))
		case 127:
			var ext [8]byte
			if _, err := io.ReadFull(w.br, ext[:]); err != nil {
				return nil, err
			}
			n = binary.BigEndian.Uint64(ext[:])
		}
		if n > 64<<20 {
			return nil, errors.New("websocket message too large")
		}
		var mask []byte
		if h[1]&0x80 != 0 {
			mask = make([]byte, 4)
			if _, err := io.ReadFull(w.br, mask); err != nil {
				return nil, err
			}
		}
		payload := make([]byte, n)
		if _, err := io.ReadFull(w.br, payload); err != nil {
			return nil, err
		}
		if mask != nil {
			for i := range payload {
				payload[i] ^= mask[i%4]
			}
		}
		switch opcode {
		case 0x8:
			return nil, errors.New("websocket closed by browser")
		case 0x9:
			if err := w.writeFrame(0xA, payload); err != nil {
				return nil, err
			}
			continue
		case 0xA:
			continue
		}
		msg = append(msg, payload...)
		if fin {
			return msg, nil
		}
	}
}

// call sends a DevTools command and waits for its result, skipping unrelated events.
func (w *wsConn) call(method string, params any, out any) error {
	w.id++
	req := map[string]any{"id": w.id, "method": method}
	if params != nil {
		req["params"] = params
	}
	b, _ := json.Marshal(req)
	if err := w.writeFrame(0x1, b); err != nil {
		return err
	}
	for {
		raw, err := w.readMessage()
		if err != nil {
			return err
		}
		var resp struct {
			ID     int             `json:"id"`
			Result json.RawMessage `json:"result"`
			Error  *struct {
				Message string `json:"message"`
			} `json:"error"`
		}
		if json.Unmarshal(raw, &resp) != nil || resp.ID != w.id {
			continue
		}
		if resp.Error != nil {
			return fmt.Errorf("%s: %s", method, resp.Error.Message)
		}
		return json.Unmarshal(resp.Result, out)
	}
}
