//go:build !linux && !windows

package main

func diskSpace(path string) (free, total uint64, ok bool) { return 0, 0, false }

func memoryInfo() (total, avail uint64, ok bool) { return 0, 0, false }
