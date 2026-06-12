//go:build !windows

// Stub non-Windows : permet `go build ./...` et `go test ./...` sur l'hôte
// Linux (la CI locale de la story) sans exclure ce package de la compilation.
// L'agent machine est un artefact Windows : l'équivalent Linux du parc reste
// le config-as-code HTTP existant (mémoire projet — Linux n'utilise pas les
// GPO ni cet agent).
package main

import (
	"fmt"
	"os"
)

func main() {
	fmt.Fprintln(os.Stderr, "agent SambaEdu : binaire Windows uniquement — cross-compiler avec CGO_ENABLED=0 GOOS=windows GOARCH=amd64 (cf. agent/build/build.sh).")
	os.Exit(1)
}
