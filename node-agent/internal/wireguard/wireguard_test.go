package wireguard_test

import (
	"context"
	"os"
	"path/filepath"
	"testing"

	"github.com/vpn-platform/node-agent/internal/config"
	"github.com/vpn-platform/node-agent/internal/wireguard"
	"golang.zx2c4.com/wireguard/wgctrl/wgtypes"
)

func TestEnsureKeypair(t *testing.T) {
	tempDir := t.TempDir()
	keyPath := filepath.Join(tempDir, "private.key")

	priv, pub, err := wireguard.EnsureKeypair(keyPath)
	if err != nil {
		t.Fatalf("failed to generate keypair: %v", err)
	}

	if priv.String() == "" || pub.String() == "" {
		t.Fatalf("expected non-empty keys")
	}

	// Verify file permissions
	info, err := os.Stat(keyPath)
	if err != nil {
		t.Fatalf("failed to stat key file: %v", err)
	}
	if info.Mode().Perm() != 0600 {
		t.Fatalf("expected 0600 file permissions, got: %v", info.Mode().Perm())
	}

	// Re-reading should yield the exact same keypair
	priv2, pub2, err := wireguard.EnsureKeypair(keyPath)
	if err != nil {
		t.Fatalf("failed to re-read keypair: %v", err)
	}
	if priv.String() != priv2.String() || pub.String() != pub2.String() {
		t.Fatalf("expected persistent keypair across reads")
	}
}

func TestValidatePublicKey(t *testing.T) {
	priv, _ := wgtypes.GeneratePrivateKey()
	validPub := priv.PublicKey().String()

	if _, err := wireguard.ValidatePublicKey(validPub); err != nil {
		t.Fatalf("expected valid key, got: %v", err)
	}

	invalidKeys := []string{
		"",
		"short-key",
		"invalid-base64-characters!@#$",
		"AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=", // invalid Curve25519 length or formatting if malformed
	}

	for _, k := range invalidKeys[:3] {
		if _, err := wireguard.ValidatePublicKey(k); err == nil {
			t.Fatalf("expected rejection for invalid key %q", k)
		}
	}
}

func TestWireguardManagerPeerLifecycle(t *testing.T) {
	cfg := config.Config{
		NodeID:             "1",
		WireGuardInterface: "wg0",
		AuthorizedPools:    []string{"10.200.20.0/24"},
	}

	mgr := wireguard.NewMockManager(cfg)
	ctx := context.Background()

	priv, _ := wgtypes.GeneratePrivateKey()
	pubKey := priv.PublicKey().String()

	// 1. Add valid peer in pool
	err := mgr.AddPeer(ctx, "peer-1", pubKey, "10.200.20.5/32")
	if err != nil {
		t.Fatalf("failed to add peer: %v", err)
	}

	// 2. Query peer
	peer, err := mgr.GetPeer(ctx, "peer-1")
	if err != nil {
		t.Fatalf("failed to get peer: %v", err)
	}
	if peer.PublicKey != pubKey || peer.AllowedIP != "10.200.20.5/32" {
		t.Fatalf("unexpected peer data: %+v", peer)
	}

	// 3. List peers
	peers, err := mgr.ListPeers(ctx)
	if err != nil {
		t.Fatalf("failed to list peers: %v", err)
	}
	if len(peers) != 1 {
		t.Fatalf("expected 1 peer, got %d", len(peers))
	}

	// 4. IP out of pool rejected
	err = mgr.AddPeer(ctx, "peer-2", pubKey, "10.100.99.5/32")
	if err != wireguard.ErrIPNotInPool {
		t.Fatalf("expected ErrIPNotInPool, got: %v", err)
	}

	// 5. Remove peer (idempotent)
	err = mgr.RemovePeer(ctx, "peer-1", pubKey)
	if err != nil {
		t.Fatalf("failed to remove peer: %v", err)
	}

	// Second remove should succeed cleanly (idempotent)
	err = mgr.RemovePeer(ctx, "peer-1", pubKey)
	if err != nil {
		t.Fatalf("expected idempotent remove, got: %v", err)
	}

	// Query after remove returns not found
	_, err = mgr.GetPeer(ctx, "peer-1")
	if err != wireguard.ErrPeerNotFound {
		t.Fatalf("expected ErrPeerNotFound, got: %v", err)
	}
}
