package api_test

import (
	"bytes"
	"encoding/json"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/vpn-platform/node-agent/internal/api"
	"github.com/vpn-platform/node-agent/internal/config"
	"github.com/vpn-platform/node-agent/internal/telemetry"
	"github.com/vpn-platform/node-agent/internal/wireguard"
	"golang.zx2c4.com/wireguard/wgctrl/wgtypes"
)

func setupTestServer(t *testing.T) (*httptest.Server, *wireguard.RealWireguardManager) {
	cfg := config.Config{
		NodeID:             "node-1",
		NodeCode:           "SG-01",
		WireGuardInterface: "wg0",
		AuthorizedPools:    []string{"10.200.20.0/24"},
		TestMode:           true,
		Version:            "1.0.0",
	}

	mgr := wireguard.NewMockManager(cfg)
	metrics := telemetry.NewMetricsCollector(mgr)
	logger := slog.New(slog.NewTextHandler(io.Discard, nil))

	mux := http.NewServeMux()
	api.RegisterRoutes(mux, logger, cfg, mgr, metrics)
	handler := api.RequestLoggerMiddleware(logger, metrics)(mux)

	server := httptest.NewServer(handler)
	t.Cleanup(server.Close)

	return server, mgr
}

func TestHealthAndStatusEndpoints(t *testing.T) {
	ts, _ := setupTestServer(t)

	// GET /internal/v1/health
	res, err := http.Get(ts.URL + "/internal/v1/health")
	if err != nil {
		t.Fatalf("health request failed: %v", err)
	}
	if res.StatusCode != http.StatusOK {
		t.Fatalf("expected 200 OK, got %d", res.StatusCode)
	}

	// GET /internal/v1/status
	res, err = http.Get(ts.URL + "/internal/v1/status")
	if err != nil {
		t.Fatalf("status request failed: %v", err)
	}
	if res.StatusCode != http.StatusOK {
		t.Fatalf("expected 200 OK, got %d", res.StatusCode)
	}
}

func TestPeerAPILifecycle(t *testing.T) {
	ts, _ := setupTestServer(t)

	priv, _ := wgtypes.GeneratePrivateKey()
	pubKey := priv.PublicKey().String()

	// 1. Add Peer
	payload := map[string]any{
		"node_id":     "node-1",
		"peer_id":     "peer-abc-123",
		"public_key":  pubKey,
		"assigned_ip": "10.200.20.50/32",
	}
	body, _ := json.Marshal(payload)

	req, _ := http.NewRequest(http.MethodPost, ts.URL+"/internal/v1/peers", bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	res, err := http.DefaultClient.Do(req)
	if err != nil {
		t.Fatalf("add peer failed: %v", err)
	}
	if res.StatusCode != http.StatusCreated {
		t.Fatalf("expected 201 Created, got %d", res.StatusCode)
	}

	// 2. Query Peer
	res, err = http.Get(ts.URL + "/internal/v1/peers/peer-abc-123")
	if err != nil {
		t.Fatalf("get peer failed: %v", err)
	}
	if res.StatusCode != http.StatusOK {
		t.Fatalf("expected 200 OK, got %d", res.StatusCode)
	}

	// 3. List Peers
	res, err = http.Get(ts.URL + "/internal/v1/peers")
	if err != nil {
		t.Fatalf("list peers failed: %v", err)
	}
	if res.StatusCode != http.StatusOK {
		t.Fatalf("expected 200 OK, got %d", res.StatusCode)
	}

	// 4. Delete Peer (idempotent)
	delReq, _ := http.NewRequest(http.MethodDelete, ts.URL+"/internal/v1/peers/peer-abc-123", nil)
	res, err = http.DefaultClient.Do(delReq)
	if err != nil {
		t.Fatalf("delete peer failed: %v", err)
	}
	if res.StatusCode != http.StatusOK {
		t.Fatalf("expected 200 OK, got %d", res.StatusCode)
	}

	// 5. Delete again (idempotent)
	res, err = http.DefaultClient.Do(delReq)
	if err != nil {
		t.Fatalf("second delete peer failed: %v", err)
	}
	if res.StatusCode != http.StatusOK {
		t.Fatalf("expected 200 OK for idempotent delete, got %d", res.StatusCode)
	}
}

func TestPeerAPIErrorsAndValidation(t *testing.T) {
	ts, _ := setupTestServer(t)

	// Invalid Public Key
	payload := map[string]any{
		"node_id":     "node-1",
		"peer_id":     "peer-bad-key",
		"public_key":  "invalid-key",
		"assigned_ip": "10.200.20.50/32",
	}
	body, _ := json.Marshal(payload)
	res, _ := http.Post(ts.URL+"/internal/v1/peers", "application/json", bytes.NewReader(body))
	if res.StatusCode != http.StatusUnprocessableEntity {
		t.Fatalf("expected 422 for invalid public key, got %d", res.StatusCode)
	}

	// IP out of authorized pool
	priv, _ := wgtypes.GeneratePrivateKey()
	payload["public_key"] = priv.PublicKey().String()
	payload["assigned_ip"] = "192.168.1.100/32"
	body, _ = json.Marshal(payload)
	res, _ = http.Post(ts.URL+"/internal/v1/peers", "application/json", bytes.NewReader(body))
	if res.StatusCode != http.StatusForbidden {
		t.Fatalf("expected 403 for IP out of pool, got %d", res.StatusCode)
	}

	// Target Node ID mismatch in header
	req, _ := http.NewRequest(http.MethodPost, ts.URL+"/internal/v1/peers", bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-Node-ID", "wrong-node-id")
	res, _ = http.DefaultClient.Do(req)
	if res.StatusCode != http.StatusForbidden {
		t.Fatalf("expected 403 for node ID mismatch, got %d", res.StatusCode)
	}
}

func TestMetricsEndpoint(t *testing.T) {
	ts, _ := setupTestServer(t)

	res, err := http.Get(ts.URL + "/metrics")
	if err != nil {
		t.Fatalf("metrics request failed: %v", err)
	}
	if res.StatusCode != http.StatusOK {
		t.Fatalf("expected 200 OK, got %d", res.StatusCode)
	}

	body, _ := io.ReadAll(res.Body)
	metricsText := string(body)

	if !strings.Contains(metricsText, "vpn_node_agent_up 1") {
		t.Fatalf("missing vpn_node_agent_up metric")
	}
	if !strings.Contains(metricsText, "vpn_wireguard_peers_total") {
		t.Fatalf("missing vpn_wireguard_peers_total metric")
	}
}
