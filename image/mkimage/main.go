// mkimage edits the FAT boot partition (partition 1) of a disk image, so a PiPusher DietPi
// image can be built on Windows without mounting anything.
//
//	mkimage -img disk.img -ls /
//	mkimage -img disk.img -cat /dietpi.txt
//	mkimage -img disk.img -put local/file:/target/path -set /dietpi.txt:KEY=value -append /config.txt:LINE ...
//
// -set replaces the active "KEY=" line of a key=value text file, else its commented-out
// "#KEY=" example line, else appends the setting. -append adds a line unless the file already
// has it (for repeatable keys such as dtoverlay=). Line endings stay LF.
package main

import (
	"flag"
	"fmt"
	"io"
	"os"
	"path"
	"sort"
	"strings"
	"time"

	diskfs "github.com/diskfs/go-diskfs"
	"github.com/diskfs/go-diskfs/filesystem"
)

type multi []string

func (m *multi) String() string     { return strings.Join(*m, ",") }
func (m *multi) Set(v string) error { *m = append(*m, v); return nil }

func main() {
	img := flag.String("img", "", "disk image to edit (in place)")
	ls := flag.String("ls", "", "list a directory of the boot partition")
	cat := flag.String("cat", "", "print a file of the boot partition")
	var puts, edits multi
	flag.Var(&puts, "put", "local:fatpath, copy a local file into the boot partition (repeatable)")
	flag.Func("set", "fatpath:KEY=VALUE, set a key in a text file (repeatable)", func(v string) error {
		return edits.Set("set\x00" + v)
	})
	flag.Func("append", "fatpath:LINE, add a line to a text file unless present (repeatable)", func(v string) error {
		return edits.Set("append\x00" + v)
	})
	flag.Parse()
	if *img == "" {
		flag.Usage()
		os.Exit(2)
	}
	if err := run(*img, *ls, *cat, puts, edits); err != nil {
		fmt.Fprintln(os.Stderr, "mkimage:", err)
		os.Exit(1)
	}
}

func run(img, ls, cat string, puts, edits []string) error {
	d, err := diskfs.Open(img, diskfs.WithOpenMode(diskfs.ReadWrite))
	if err != nil {
		return err
	}
	defer d.Close()
	fs, err := d.GetFilesystem(1)
	if err != nil {
		return fmt.Errorf("boot partition: %w", err)
	}
	if fs.Type() != filesystem.TypeFat32 {
		return fmt.Errorf("partition 1 is not FAT32")
	}

	if ls != "" {
		entries, err := fs.ReadDir(fsPath(ls))
		if err != nil {
			return err
		}
		for _, e := range entries {
			info, _ := e.Info()
			size, mod := int64(0), ""
			if info != nil {
				size, mod = info.Size(), info.ModTime().Format("2006-01-02 15:04")
			}
			kind := " "
			if e.IsDir() {
				kind = "d"
			}
			fmt.Printf("%s %10d  %s  %s\n", kind, size, mod, e.Name())
		}
	}
	if cat != "" {
		b, err := readFile(fs, cat)
		if err != nil {
			return err
		}
		os.Stdout.Write(b)
	}

	for _, p := range puts {
		local, target, ok := strings.Cut(p, ":/")
		if !ok {
			return fmt.Errorf("-put %q: want local:/fatpath", p)
		}
		b, err := os.ReadFile(local)
		if err != nil {
			return err
		}
		if err := writeFile(fs, "/"+target, b); err != nil {
			return fmt.Errorf("put %s: %w", target, err)
		}
		fmt.Printf("put  /%s (%d bytes)\n", target, len(b))
	}

	// Group -set/-append by file (in command line order) so each file is rewritten once.
	type edit struct{ op, arg string }
	byFile := map[string][]edit{}
	for _, s := range edits {
		op, rest, _ := strings.Cut(s, "\x00")
		file, arg, ok := strings.Cut(rest, ":")
		if !ok || (op == "set" && !strings.Contains(arg, "=")) {
			return fmt.Errorf("-%s %q: want /fatpath:%s", op, rest, map[string]string{"set": "KEY=VALUE", "append": "LINE"}[op])
		}
		byFile[file] = append(byFile[file], edit{op, arg})
	}
	files := make([]string, 0, len(byFile))
	for f := range byFile {
		files = append(files, f)
	}
	sort.Strings(files)
	for _, file := range files {
		b, err := readFile(fs, file)
		if err != nil {
			return fmt.Errorf("edit %s: %w", file, err)
		}
		text := string(b)
		for _, e := range byFile[file] {
			if e.op == "set" {
				text = setKey(text, e.arg)
			} else {
				text = appendLine(text, e.arg)
			}
			fmt.Printf("%-6s %s: %s\n", e.op, file, e.arg)
		}
		if err := writeFile(fs, file, []byte(text)); err != nil {
			return fmt.Errorf("set %s: %w", file, err)
		}
	}
	return nil
}

// appendLine adds line at the end unless an identical (trimmed) line is already there.
func appendLine(text, line string) string {
	for _, l := range strings.Split(text, "\n") {
		if strings.TrimSpace(l) == line {
			return text
		}
	}
	return strings.TrimRight(text, "\n") + "\n" + line + "\n"
}

// setKey sets KEY=VALUE in a key=value text: the first active line wins, then a commented example.
func setKey(text, kv string) string {
	key, _, _ := strings.Cut(kv, "=")
	lines := strings.Split(text, "\n")
	commented := -1
	for i, l := range lines {
		t := strings.TrimSpace(l)
		if strings.HasPrefix(t, key+"=") {
			lines[i] = kv
			return strings.Join(lines, "\n")
		}
		if commented < 0 && strings.HasPrefix(strings.TrimLeft(t, "# "), key+"=") && strings.HasPrefix(t, "#") {
			commented = i
		}
	}
	if commented >= 0 {
		lines[commented] = kv
		return strings.Join(lines, "\n")
	}
	return strings.TrimRight(text, "\n") + "\n" + kv + "\n"
}

func writeFile(fs filesystem.FileSystem, p string, b []byte) error {
	if dir := path.Dir(p); dir != "/" {
		if err := fs.Mkdir(dir); err != nil {
			return err
		}
	}
	f, err := fs.OpenFile(p, os.O_RDWR|os.O_CREATE|os.O_TRUNC)
	if err != nil {
		return err
	}
	if _, err := f.Write(b); err != nil {
		f.Close()
		return err
	}
	if err := f.Close(); err != nil {
		return err
	}
	// go-diskfs leaves new files dated 1980 and edited ones unchanged, but DietPi's first boot copies
	// dietpi.txt and the Automation scripts to /boot with "cp -u" (only when newer), so date them now.
	now := time.Now()
	if err := fs.Chtimes("/"+fsPath(p), now, now, now); err != nil {
		return err
	}
	// Read back to make sure the image holds exactly what was written.
	got, err := readFile(fs, p)
	if err != nil {
		return err
	}
	if string(got) != string(b) {
		return fmt.Errorf("verification failed: wrote %d bytes, read back %d", len(b), len(got))
	}
	return nil
}

// readFile reads exactly the file's recorded size. go-diskfs v1.9.4's ReadFile can return extra bytes
// for files between one sector and one cluster in size; the directory entry's size is correct.
func readFile(fs filesystem.FileSystem, p string) ([]byte, error) {
	st, err := fs.Stat(fsPath(p))
	if err != nil {
		return nil, err
	}
	f, err := fs.OpenFile("/"+fsPath(p), os.O_RDONLY)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	b := make([]byte, st.Size())
	_, err = io.ReadFull(f, b)
	return b, err
}

// fsPath turns "/a/b" into "a/b" ("/" into "."): the io/fs read methods reject absolute paths.
func fsPath(p string) string {
	p = strings.TrimPrefix(path.Clean("/"+p), "/")
	if p == "" {
		return "."
	}
	return p
}
