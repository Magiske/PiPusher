//go:build !linux

package main

func diskFree(path string) (uint64, bool) { return 0, false }
