package wireguard

import (
	"fmt"
	"os"
	"path/filepath"

	"golang.zx2c4.com/wireguard/wgctrl/wgtypes"
)

// EnsureKeypair ensures a WireGuard private key exists at the given path with 0600 permissions.
// If it does not exist, it generates a fresh Curve25519 keypair and writes it.
// Returns the private key and public key as wgtypes.Key.
func EnsureKeypair(keyPath string) (wgtypes.Key, wgtypes.Key, error) {
	if keyPath == "" {
		keyPath = "/etc/wireguard/private.key"
	}

	if data, err := os.ReadFile(keyPath); err == nil {
		privKey, err := wgtypes.ParseKey(string(data))
		if err == nil {
			return privKey, privKey.PublicKey(), nil
		}
	}

	// Generate fresh key
	privKey, err := wgtypes.GeneratePrivateKey()
	if err != nil {
		return wgtypes.Key{}, wgtypes.Key{}, fmt.Errorf("failed to generate wireguard private key: %w", err)
	}

	dir := filepath.Dir(keyPath)
	if err := os.MkdirAll(dir, 0700); err != nil {
		return wgtypes.Key{}, wgtypes.Key{}, fmt.Errorf("failed to create directory for key: %w", err)
	}

	if err := os.WriteFile(keyPath, []byte(privKey.String()+"\n"), 0600); err != nil {
		return wgtypes.Key{}, wgtypes.Key{}, fmt.Errorf("failed to write private key to %s: %w", keyPath, err)
	}

	return privKey, privKey.PublicKey(), nil
}

// ValidatePublicKey checks if a given string is a valid WireGuard 32-byte Base64 public key.
func ValidatePublicKey(pubKeyStr string) (wgtypes.Key, error) {
	key, err := wgtypes.ParseKey(pubKeyStr)
	if err != nil {
		return wgtypes.Key{}, ErrInvalidPublicKey
	}
	return key, nil
}
