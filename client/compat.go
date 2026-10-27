package main

// The client must also build with Go 1.20, the last Go release that runs on Windows 7/8/8.1
// (build.ps1 makes a separate legacy exe with it). So: no min/max builtins, no slices/maps
// packages and no method or wildcard patterns in http.ServeMux.

func maxInt(a, b int) int {
	if a > b {
		return a
	}
	return b
}
