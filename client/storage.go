package main

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"net"
	"net/http"
	"net/url"
	"sort"
	"strings"
	"time"
)

// Local storage entries for web pages (URL media), for sites that keep an accepted cookie banner there instead of in a
// cookie. A page inside a layout zone is third-party to the player, and browsers keep its local storage apart from the
// same site opened on its own, so the entries have to be written from a frame of that site inside the player. The
// player gets a hidden iframe of <site>/.pipusher-storage, which never reaches the site: the browser is made to answer
// it with a small page that writes the entries. On Pi and Windows that is DevTools request interception, on Android the
// app (control_url) does it in a hidden WebView with the player's origin.

const storagePath = "/.pipusher-storage"

// storageOrigins is the site of a web page and its www./bare twin (sites often redirect between the two).
func storageOrigins(page string) []string {
	u, err := url.Parse(page)
	if err != nil || u.Host == "" || (u.Scheme != "http" && u.Scheme != "https") {
		return nil
	}
	host := strings.ToLower(u.Host)
	out := []string{u.Scheme + "://" + host}
	name := strings.ToLower(u.Hostname())
	switch {
	case net.ParseIP(name) != nil:
	case strings.HasPrefix(host, "www."):
		out = append(out, u.Scheme+"://"+strings.TrimPrefix(host, "www."))
	case strings.Count(name, ".") == 1:
		out = append(out, u.Scheme+"://www."+host)
	}
	return out
}

// storagePages maps each URL to load to the page that answers it, writing the entries.
func storagePages(pages []pageCookies) map[string]string {
	out := map[string]string{}
	for _, p := range pages {
		if len(p.Storage) == 0 {
			continue
		}
		items := map[string]string{}
		for _, c := range p.Storage {
			items[c.Name] = c.Value
		}
		b, _ := json.Marshal(items)
		// JSON is valid JavaScript; </ is escaped so a value cannot end the script.
		js := strings.ReplaceAll(string(b), "</", `<\/`)
		html := "<!doctype html><script>try{var s=" + js + ";for(var k in s)localStorage.setItem(k,s[k])}catch(e){}</script>"
		for _, o := range storageOrigins(p.URL) {
			out[o+storagePath] = html
		}
	}
	return out
}

func (a *Agent) setStorage(ctx context.Context, pages []pageCookies) error {
	serve := storagePages(pages)
	if len(serve) == 0 {
		return nil
	}
	if a.controlled() {
		b, _ := json.Marshal(serve)
		_, err := a.controlCall(ctx, http.MethodPost, "storage?"+url.Values{"pages": {string(b)}}.Encode())
		return err
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
	pattern := map[string]any{"urlPattern": "*" + storagePath + "*", "requestStage": "Request"}
	if err := conn.call("Fetch.enable", map[string]any{"patterns": []any{pattern}}, &ignore); err != nil {
		return err
	}
	urls := make([]string, 0, len(serve))
	for u := range serve {
		urls = append(urls, u)
	}
	sort.Strings(urls)
	list, _ := json.Marshal(urls)
	// Hidden iframes in the player, removed again once loaded.
	expr := `new Promise(function(done){var urls=` + string(list) + `,left=urls.length;` +
		`var t=setTimeout(function(){done('timeout')},20000);urls.forEach(function(u){` +
		`var f=document.createElement('iframe');f.style.display='none';f.onload=function(){f.remove();` +
		`if(--left===0){clearTimeout(t);done('ok')}};f.src=u;document.documentElement.appendChild(f)})})`
	evalID, err := conn.send("Runtime.evaluate", map[string]any{"expression": expr, "awaitPromise": true, "returnByValue": true})
	if err != nil {
		return err
	}
	for {
		raw, err := conn.readMessage()
		if err != nil {
			return err
		}
		var msg struct {
			ID     int    `json:"id"`
			Method string `json:"method"`
			Params struct {
				RequestID string `json:"requestId"`
				Request   struct {
					URL string `json:"url"`
				} `json:"request"`
			} `json:"params"`
			Result struct {
				Result struct {
					Value any `json:"value"`
				} `json:"result"`
				ExceptionDetails any `json:"exceptionDetails"`
			} `json:"result"`
			Error *struct {
				Message string `json:"message"`
			} `json:"error"`
		}
		if json.Unmarshal(raw, &msg) != nil {
			continue
		}
		if msg.Method == "Fetch.requestPaused" {
			u := msg.Params.Request.URL
			if i := strings.IndexAny(u, "?#"); i >= 0 {
				u = u[:i]
			}
			html, ok := serve[u]
			if !ok {
				html = "<!doctype html>"
			}
			if _, err := conn.send("Fetch.fulfillRequest", map[string]any{
				"requestId": msg.Params.RequestID, "responseCode": 200,
				"responseHeaders": []any{
					map[string]string{"name": "Content-Type", "value": "text/html; charset=utf-8"},
					map[string]string{"name": "Cache-Control", "value": "no-store"},
				},
				"body": base64.StdEncoding.EncodeToString([]byte(html)),
			}); err != nil {
				return err
			}
			continue
		}
		if msg.ID != evalID {
			continue
		}
		_, _ = conn.send("Fetch.disable", nil)
		switch {
		case msg.Error != nil:
			return fmt.Errorf("local storage: %s", msg.Error.Message)
		case msg.Result.ExceptionDetails != nil:
			return fmt.Errorf("local storage: the player page failed")
		case msg.Result.Result.Value != "ok":
			return fmt.Errorf("local storage: the pages did not load (%v)", msg.Result.Result.Value)
		}
		return nil
	}
}

// send sends a DevTools command without waiting for its result, which arrives like any other message.
func (w *wsConn) send(method string, params any) (int, error) {
	w.id++
	req := map[string]any{"id": w.id, "method": method}
	if params != nil {
		req["params"] = params
	}
	b, _ := json.Marshal(req)
	return w.id, w.writeFrame(0x1, b)
}
