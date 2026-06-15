package shared

import _ "embed"

// rainmeterSkinSource : copie EMBARQUÉE (UTF-8) de la skin canonique du repo
// `resources/overlay/rainmeter/SambaEduOverlay/SambaEduOverlay.ini`. `go:embed`
// ne peut référencer que des fichiers du dossier du package (hors arborescence
// du module en dehors) — cette copie sous agent/shared/embedded/ est donc
// maintenue IDENTIQUE à la source repo (la skin canonique reste l'autorité,
// enrichie en T6 ; toute divergence est un bug à corriger côté copie). L'agent
// la convertit en UTF-16 LE + BOM À LA POSE (D6) — la source reste UTF-8.
//
//go:embed embedded/SambaEduOverlay.ini
var rainmeterSkinSource string

// RainmeterSkinSource expose la source UTF-8 embarquée (testabilité).
func RainmeterSkinSource() string { return rainmeterSkinSource }
