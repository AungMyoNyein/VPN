package adapter_test

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/vpn-platform/control-plane/internal/adapter"
)

func TestRemoteNodeAdapterAndMultiNodeAdapter(t *testing.T) {
	// Create a mock remote Node Agent HTTP server
	mockAgent := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		switch {
		case r.Method == http.MethodPost && r.URL.Path == "/internal/v1/peers":
			w.WriteHeader(http.StatusCreated)
			_ = json.NewEncoder(w).Encode(map[string]any{
				"data": map[string]any{
					"peer_id": "remote-peer-1",
					"status":  "ACTIVE",
				},
			})
		case r.Method == http.MethodGet && r.URL.Path == "/internal/v1/peers/remote-peer-1":
			w.WriteHeader(http.StatusOK)
			_ = json.NewEncoder(w).Encode(map[string]any{
				"data": map[string]any{
					"peer_id":             "remote-peer-1",
					"public_key":          "KEY=",
					"allowed_ip":          "10.200.20.10/32",
					"rx_bytes":            1024,
					"tx_bytes":            2048,
					"latest_handshake_at": time.Now(),
				},
			})
		case r.Method == http.MethodDelete && r.URL.Path == "/internal/v1/peers/remote-peer-1":
			w.WriteHeader(http.StatusOK)
			_ = json.NewEncoder(w).Encode(map[string]any{
				"data": map[string]any{"removed": true},
			})
		case r.Method == http.MethodGet && r.URL.Path == "/internal/v1/health":
			w.WriteHeader(http.StatusOK)
			_ = json.NewEncoder(w).Encode(map[string]any{
				"status": "HEALTHY",
			})
		default:
			w.WriteHeader(http.StatusOK)
		}
	}))
	defer mockAgent.Close()

	remoteAdapter, err := adapter.NewRemoteNodeAdapter([]adapter.RemoteNodeConfig{
		{
			NodeID:      "remote-node-1",
			Endpoint:    mockAgent.URL,
			AdapterType: "remote",
		},
	})
	if err != nil {
		t.Fatalf("failed to create remote adapter: %v", err)
	}

	fakeAdapter := adapter.NewFakeNodeAdapter()
	multi := adapter.NewMultiNodeAdapter(fakeAdapter, remoteAdapter)
	multi.SetNodeType("remote-node-1", "remote")
	multi.SetNodeType("1", "fake")

	ctx := context.Background()

	// 1. Add peer to remote node
	err = multi.AddPeer(ctx, adapter.AddPeerRequest{
		NodeID:     "remote-node-1",
		PeerID:     "remote-peer-1",
		PublicKey:  "KEY=",
		AssignedIP: "10.200.20.10/32",
	})
	if err != nil {
		t.Fatalf("remote add peer failed: %v", err)
	}

	// 2. Get peer from remote node
	peer, err := multi.GetPeer(ctx, "remote-peer-1")
	if err != nil {
		t.Fatalf("remote get peer failed: %v", err)
	}
	if peer.ReceiveBytes != 1024 || peer.TransmitBytes != 2048 {
		t.Fatalf("unexpected peer telemetry: %+v", peer)
	}

	// 3. Remove peer from remote node
	err = multi.RemovePeer(ctx, adapter.RemovePeerRequest{
		NodeID: "remote-node-1",
		PeerID: "remote-peer-1",
	})
	if err != nil {
		t.Fatalf("remote remove peer failed: %v", err)
	}

	// 4. Add peer to fake node still works
	err = multi.AddPeer(ctx, adapter.AddPeerRequest{
		NodeID:     "1",
		PeerID:     "fake-peer-1",
		PublicKey:  "FAKE_KEY=",
		AssignedIP: "10.200.10.5",
	})
	if err != nil {
		t.Fatalf("fake add peer failed: %v", err)
	}

	fakePeer, err := multi.GetPeer(ctx, "fake-peer-1")
	if err != nil || fakePeer.PeerID != "fake-peer-1" {
		t.Fatalf("fake get peer failed: %v", err)
	}
}
