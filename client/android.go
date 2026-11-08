//go:build android || pipusher_android

package main

// Builds for the Android app (build-android.ps1 adds the pipusher_android tag to its Linux builds for 32-bit ARM
// x86_64 and x86): report "android", and carry the time zone database since Android keeps its own in another format.

import _ "time/tzdata"

func runtimeGOOS() string { return "android" }
