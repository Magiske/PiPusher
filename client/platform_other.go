//go:build !android && !pipusher_android

package main

import "runtime"

func runtimeGOOS() string { return runtime.GOOS }
