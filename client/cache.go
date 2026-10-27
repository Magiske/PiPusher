package main

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"io/fs"
	"log"
	"net/url"
	"os"
	"path"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
)

// Cache stores media files under <data>/media/<media id>/<relative path> and remembers the
// SHA-256 of every file so unchanged files are never downloaded twice.
type Cache struct {
	root      string
	indexPath string

	mu    sync.Mutex
	index map[string]string // "<id>/<path>" -> sha256
	size  int64
}

func NewCache(dataDir string) *Cache {
	c := &Cache{
		root:      filepath.Join(dataDir, "media"),
		indexPath: filepath.Join(dataDir, "media-index.json"),
		index:     map[string]string{},
	}
	_ = os.MkdirAll(c.root, 0o755)
	if b, err := os.ReadFile(c.indexPath); err == nil {
		_ = json.Unmarshal(b, &c.index)
	}
	return c
}

func queryEscape(s string) string { return url.QueryEscape(s) }

// LocalPath maps a media id and bundle-relative path to a file inside the cache, refusing
// anything that would escape it.
func (c *Cache) LocalPath(id, rel string) (string, error) {
	if _, err := strconv.Atoi(id); err != nil {
		return "", fmt.Errorf("bad media id %q", id)
	}
	clean := path.Clean("/" + strings.ReplaceAll(rel, "\\", "/"))
	if clean == "/" || strings.Contains(clean, "\x00") {
		return "", fmt.Errorf("bad path %q", rel)
	}
	p := filepath.Join(c.root, id, filepath.FromSlash(clean[1:]))
	if !strings.HasPrefix(p, filepath.Join(c.root, id)+string(filepath.Separator)) {
		return "", fmt.Errorf("bad path %q", rel)
	}
	return p, nil
}

type downloadFunc func(ctx context.Context, mediaID int, rel string, w io.Writer) error

// Ensure makes sure every file of every media item is present with the right hash.
func (c *Cache) Ensure(ctx context.Context, media map[string]Media, download downloadFunc) error {
	var total int64
	for _, m := range media {
		if m.Type == "url" {
			continue
		}
		for _, f := range m.Files {
			total += f.Size
			if err := c.ensureFile(ctx, m.ID, f, download); err != nil {
				return err
			}
		}
	}
	c.mu.Lock()
	c.size = total
	c.mu.Unlock()
	return c.saveIndex()
}

func (c *Cache) ensureFile(ctx context.Context, id int, f MediaFile, download downloadFunc) error {
	key := strconv.Itoa(id) + "/" + f.Path
	dest, err := c.LocalPath(strconv.Itoa(id), f.Path)
	if err != nil {
		return err
	}
	c.mu.Lock()
	known := c.index[key]
	c.mu.Unlock()
	if st, err := os.Stat(dest); err == nil && known == f.SHA256 && st.Size() == f.Size {
		return nil
	}
	if err := os.MkdirAll(filepath.Dir(dest), 0o755); err != nil {
		return err
	}
	log.Printf("downloading media %d/%s (%d bytes)", id, f.Path, f.Size)
	tmp := dest + ".part"
	out, err := os.Create(tmp)
	if err != nil {
		return err
	}
	h := sha256.New()
	err = download(ctx, id, f.Path, io.MultiWriter(out, h))
	if cerr := out.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		os.Remove(tmp)
		return err
	}
	if got := hex.EncodeToString(h.Sum(nil)); got != f.SHA256 {
		os.Remove(tmp)
		return fmt.Errorf("checksum mismatch for media %d/%s", id, f.Path)
	}
	if err := os.Rename(tmp, dest); err != nil {
		return err
	}
	c.mu.Lock()
	c.index[key] = f.SHA256
	c.mu.Unlock()
	return nil
}

// GC removes cached files no longer referenced by the active configuration.
func (c *Cache) GC(media map[string]Media) {
	keep := map[string]bool{}
	for _, m := range media {
		for _, f := range m.Files {
			keep[strconv.Itoa(m.ID)+"/"+f.Path] = true
		}
	}
	_ = filepath.WalkDir(c.root, func(p string, d fs.DirEntry, err error) error {
		if err != nil || d.IsDir() {
			return nil
		}
		rel, _ := filepath.Rel(c.root, p)
		if !keep[filepath.ToSlash(rel)] {
			log.Printf("cache: removing %s", rel)
			os.Remove(p)
		}
		return nil
	})
	removeEmptyDirs(c.root)
	c.mu.Lock()
	for k := range c.index {
		if !keep[k] {
			delete(c.index, k)
		}
	}
	c.mu.Unlock()
	_ = c.saveIndex()
}

func removeEmptyDirs(root string) {
	var dirs []string
	_ = filepath.WalkDir(root, func(p string, d fs.DirEntry, err error) error {
		if err == nil && d.IsDir() && p != root {
			dirs = append(dirs, p)
		}
		return nil
	})
	for i := len(dirs) - 1; i >= 0; i-- {
		os.Remove(dirs[i]) // only succeeds when empty
	}
}

func (c *Cache) saveIndex() error {
	c.mu.Lock()
	defer c.mu.Unlock()
	return writeJSONAtomic(c.indexPath, c.index, 0o644)
}

func (c *Cache) Size() int64 {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.size
}
