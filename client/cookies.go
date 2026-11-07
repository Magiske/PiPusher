package main

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"log"
	"net"
	"net/http"
	"net/url"
	"sort"
	"strings"
	"time"
)

// Cookies for web pages (URL media), set in the screen's browser before the pages are shown: typically an accepted
// cookie banner copied from the admin's own browser, so a kiosk never shows the banner. On Pi and Windows they go in
// through Chromium's DevTools, on Android through the app (control_url). They are set again when the content changes
// and once a day, which also renews their expiry. Local storage entries go along with them (storage.go).

type Cookie struct {
	Name  string `json:"name"`
	Value string `json:"value"`
}

// pageCookies is the cookies and local storage entries of one web page.
type pageCookies struct {
	URL     string
	Cookies []Cookie
	Storage []Cookie
}

func wantedCookies(config json.RawMessage) []pageCookies {
	var p Payload
	if json.Unmarshal(config, &p) != nil {
		return nil
	}
	var out []pageCookies
	for _, m := range p.Media {
		if m.Type == "url" && (len(m.Cookies) > 0 || len(m.Storage) > 0) && m.URL != "" {
			out = append(out, pageCookies{m.URL, m.Cookies, m.Storage})
		}
	}
	sort.Slice(out, func(i, j int) bool { return out[i].URL < out[j].URL })
	return out
}

// cookieLife is how long a set cookie lasts; Chrome caps cookie lifetimes at 400 days.
const cookieLife = 400 * 24 * time.Hour

// watchCookies keeps the cookies of the content on screen set in the browser.
func (a *Agent) watchCookies(ctx context.Context) {
	applied, at := "", time.Time{}
	failed := ""
	for {
		a.mu.RLock()
		config := a.st.Config
		a.mu.RUnlock()
		want := wantedCookies(config)
		b, _ := json.Marshal(want)
		sum := sha256.Sum256(b)
		key := hex.EncodeToString(sum[:])
		if len(want) > 0 && (key != applied || time.Since(at) > 24*time.Hour) {
			err := a.setCookies(ctx, want)
			if err == nil {
				err = a.setStorage(ctx, want)
			}
			if err != nil {
				if err.Error() != failed { // the browser may not be up yet: log each new reason once
					log.Printf("cookies: %v", err)
					failed = err.Error()
				}
			} else {
				n, s := 0, 0
				for _, p := range want {
					n, s = n+len(p.Cookies), s+len(p.Storage)
				}
				log.Printf("cookies: %d cookie(s) and %d local storage entries set for %d web page(s)", n, s, len(want))
				applied, at, failed = key, time.Now(), ""
			}
		}
		select {
		case <-ctx.Done():
			return
		case <-time.After(15 * time.Second):
		}
	}
}

func (a *Agent) setCookies(ctx context.Context, pages []pageCookies) error {
	if a.controlled() {
		for _, p := range pages {
			if len(p.Cookies) == 0 {
				continue
			}
			var lines []string
			for _, c := range p.Cookies {
				if line, ok := setCookieLine(p.URL, c); ok {
					lines = append(lines, line)
				}
			}
			q := url.Values{"url": {p.URL}, "set": {strings.Join(lines, "\n")}}
			if _, err := a.controlCall(ctx, http.MethodPost, "cookies?"+q.Encode()); err != nil {
				return err
			}
		}
		return nil
	}
	var list []map[string]any
	for _, p := range pages {
		for _, c := range p.Cookies {
			if dc, ok := devtoolsCookie(p.URL, c); ok {
				list = append(list, dc)
			}
		}
	}
	if len(list) == 0 {
		return nil
	}
	ctx, cancel := context.WithTimeout(ctx, 30*time.Second)
	defer cancel()
	wsURL, err := a.findPlayerTarget(ctx)
	if err != nil {
		return err
	}
	conn, err := dialWebSocket(ctx, wsURL)
	if err != nil {
		return fmt.Errorf("devtools: %w", err)
	}
	defer conn.Close()
	var ignore json.RawMessage
	return conn.call("Network.setCookies", map[string]any{"cookies": list}, &ignore)
}

// cookieScope is where a page's cookie goes: the site's domain (www. dropped, so subdomains share it, as consent
// cookies usually are), or the host alone for an IP address. Pages inside a layout zone are third-party to the player,
// where browsers only send cookies marked Secure and SameSite=None, so this works for https pages.
func cookieScope(page string) (host, domain string, secure, ok bool) {
	u, err := url.Parse(page)
	if err != nil || u.Hostname() == "" {
		return "", "", false, false
	}
	host = strings.ToLower(u.Hostname())
	secure = u.Scheme == "https"
	if net.ParseIP(host) != nil || !strings.Contains(host, ".") {
		return host, "", secure, true
	}
	return host, "." + strings.TrimPrefix(host, "www."), secure, true
}

func devtoolsCookie(page string, c Cookie) (map[string]any, bool) {
	host, domain, secure, ok := cookieScope(page)
	if !ok {
		return nil, false
	}
	dc := map[string]any{
		"name": c.Name, "value": c.Value, "path": "/", "secure": secure,
		"expires": float64(time.Now().Add(cookieLife).Unix()),
	}
	if domain != "" {
		dc["domain"] = domain
	} else {
		scheme := "http"
		if secure {
			scheme = "https"
		}
		dc["url"] = scheme + "://" + host + "/"
	}
	if secure {
		dc["sameSite"] = "None"
	}
	return dc, true
}

// setCookieLine is the cookie as a Set-Cookie value, for Android's CookieManager.
func setCookieLine(page string, c Cookie) (string, bool) {
	_, domain, secure, ok := cookieScope(page)
	if !ok {
		return "", false
	}
	line := fmt.Sprintf("%s=%s; Path=/; Max-Age=%d", c.Name, c.Value, int(cookieLife.Seconds()))
	if domain != "" {
		line += "; Domain=" + domain
	}
	if secure {
		line += "; Secure; SameSite=None"
	}
	return line, true
}
