package api

import (
	"bytes"
	"encoding/json"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"os"
	"testing"

	"github.com/vpn-platform/control-plane/internal/adapter"
	"github.com/vpn-platform/control-plane/internal/config"
	"github.com/vpn-platform/control-plane/internal/health"
)

func setupTestServer() (http.Handler, *adapter.FakeNodeAdapter) {
	logger := slog.New(slog.NewTextHandler(os.Stderr, nil))
	cfg := config.Config{ServiceToken: "secret-token"}
	fakeAdapter := adapter.NewFakeNodeAdapter()

	mux := http.NewServeMux()
	RegisterRoutes(mux, logger, cfg, fakeAdapter)
	mux.HandleFunc("GET /internal/v1/health", health.Handler)
	handler := RequestIDMiddleware(logger)(mux)

	return handler, fakeAdapter
}

func TestHealthEndpoint(t *testing.T) {
	req := httptest.NewRequest(http.MethodGet, "/internal/v1/health", nil)
	rr := httptest.NewRecorder()
	health.Handler(rr, req)
	if rr.Code != http.StatusOK {
		t.Fatalf("status=%d", rr.Code)
	}
	var body map[string]any
	if err := json.Unmarshal(rr.Body.Bytes(), &body); err != nil {
		t.Fatal(err)
	}
	data := body["data"].(map[string]any)
	if data["status"] != "ok" {
		t.Fatalf("unexpected body: %v", body)
	}
}

func TestPeersRequiresAuth(t *testing.T) {
	handler, _ := setupTestServer()

	req := httptest.NewRequest(http.MethodPost, "/internal/v1/peers", nil)
	rr := httptest.NewRecorder()
	handler.ServeHTTP(rr, req)
	if rr.Code != http.StatusUnauthorized {
		t.Fatalf("expected 401, got %d", rr.Code)
	}
}

func TestNodesWithAuth(t *testing.T) {
	handler, _ := setupTestServer()

	req := httptest.NewRequest(http.MethodGet, "/internal/v1/nodes", nil)
	req.Header.Set("Authorization", "Bearer secret-token")
	rr := httptest.NewRecorder()
	handler.ServeHTTP(rr, req)
	if rr.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d body=%s", rr.Code, rr.Body.String())
	}
}

func TestAddGetRemovePeerLifecycle(t *testing.T) {
	handler, _ := setupTestServer()

	// 1. Add Peer
	payload := map[string]any{
		"node_id":     "1",
		"peer_id":     "WG-PEER-TEST-001",
		"public_key":  "TEST_CLIENT_PUBLIC_KEY_BASE64_32_BYTES=",
		"assigned_ip": "10.200.10.2",
		"allowed_ips": []string{"0.0.0.0/0", "::/0"},
	}
	bodyBytes, _ := json.Marshal(payload)

	req := httptest.NewRequest(http.MethodPost, "/internal/v1/peers", bytes.NewReader(bodyBytes))
	req.Header.Set("Authorization", "Bearer secret-token")
	req.Header.Set("Content-Type", "application/json")
	rr := httptest.NewRecorder()
	handler.ServeHTTP(rr, req)

	if rr.Code != http.StatusCreated {
		t.Fatalf("expected 201 Created, got %d: %s", rr.Code, rr.Body.String())
	}

	// 2. Get Peer
	getReq := httptest.NewRequest(http.MethodGet, "/internal/v1/peers/WG-PEER-TEST-001", nil)
	getReq.Header.Set("Authorization", "Bearer secret-token")
	getRr := httptest.NewRecorder()
	handler.ServeHTTP(getRr, getReq)

	if getRr.Code != http.StatusOK {
		t.Fatalf("expected 200 OK, got %d: %s", getRr.Code, getRr.Body.String())
	}

	// 3. List Peers
	listReq := httptest.NewRequest(http.MethodGet, "/internal/v1/peers?node_id=1", nil)
	listReq.Header.Set("Authorization", "Bearer secret-token")
	listRr := httptest.NewRecorder()
	handler.ServeHTTP(listRr, listReq)

	if listRr.Code != http.StatusOK {
		t.Fatalf("expected 200 OK, got %d: %s", listRr.Code, listRr.Body.String())
	}

	// 4. Remove Peer
	delReq := httptest.NewRequest(http.MethodDelete, "/internal/v1/peers/WG-PEER-TEST-001?node_id=1", nil)
	delReq.Header.Set("Authorization", "Bearer secret-token")
	delRr := httptest.NewRecorder()
	handler.ServeHTTP(delRr, delReq)

	if delRr.Code != http.StatusOK {
		t.Fatalf("expected 200 OK, got %d: %s", delRr.Code, delRr.Body.String())
	}

	// 5. Verify peer is removed
	getReq2 := httptest.NewRequest(http.MethodGet, "/internal/v1/peers/WG-PEER-TEST-001", nil)
	getReq2.Header.Set("Authorization", "Bearer secret-token")
	getRr2 := httptest.NewRecorder()
	handler.ServeHTTP(getRr2, getReq2)

	if getRr2.Code != http.StatusNotFound {
		t.Fatalf("expected 404 Not Found, got %d", getRr2.Code)
	}
}

func TestDrainAndMaintenanceOperations(t *testing.T) {
	handler, _ := setupTestServer()

	// Drain node 1
	drainReq := httptest.NewRequest(http.MethodPost, "/internal/v1/nodes/1/drain", bytes.NewReader([]byte(`{"drain":true}`)))
	drainReq.Header.Set("Authorization", "Bearer secret-token")
	drainRr := httptest.NewRecorder()
	handler.ServeHTTP(drainRr, drainReq)
	if drainRr.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d", drainRr.Code)
	}

	// Set Maintenance on node 2
	maintReq := httptest.NewRequest(http.MethodPost, "/internal/v1/nodes/2/maintenance", bytes.NewReader([]byte(`{"maintenance":true}`)))
	maintReq.Header.Set("Authorization", "Bearer secret-token")
	maintRr := httptest.NewRecorder()
	handler.ServeHTTP(maintRr, maintReq)
	if maintRr.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d", maintRr.Code)
	}

	// Attempt AddPeer on node in maintenance -> expect 409 Conflict
	payload := map[string]any{
		"node_id":     "2",
		"peer_id":     "WG-PEER-MAINT",
		"public_key":  "SOME_KEY",
		"assigned_ip": "10.200.20.2",
	}
	bodyBytes, _ := json.Marshal(payload)
	req := httptest.NewRequest(http.MethodPost, "/internal/v1/peers", bytes.NewReader(bodyBytes))
	req.Header.Set("Authorization", "Bearer secret-token")
	rr := httptest.NewRecorder()
	handler.ServeHTTP(rr, req)

	if rr.Code != http.StatusConflict {
		t.Fatalf("expected 409 Conflict, got %d: %s", rr.Code, rr.Body.String())
	}
}

func TestFailureInjection(t *testing.T) {
	handler, _ := setupTestServer()

	// Inject add_peer failure
	injReq := httptest.NewRequest(http.MethodPost, "/internal/v1/test/inject-failure", bytes.NewReader([]byte(`{"action":"add_peer","failure_type":"error"}`)))
	injReq.Header.Set("Authorization", "Bearer secret-token")
	injRr := httptest.NewRecorder()
	handler.ServeHTTP(injRr, injReq)
	if injRr.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d", injRr.Code)
	}

	// AddPeer should fail
	payload := map[string]any{
		"node_id":     "1",
		"peer_id":     "WG-PEER-FAIL",
		"public_key":  "KEY",
		"assigned_ip": "10.200.10.3",
	}
	bodyBytes, _ := json.Marshal(payload)
	req := httptest.NewRequest(http.MethodPost, "/internal/v1/peers", bytes.NewReader(bodyBytes))
	req.Header.Set("Authorization", "Bearer secret-token")
	rr := httptest.NewRecorder()
	handler.ServeHTTP(rr, req)

	if rr.Code != http.StatusInternalServerError {
		t.Fatalf("expected 500 error from injected failure, got %d: %s", rr.Code, rr.Body.String())
	}

	// Next attempt should succeed because failure injection was consumed
	req2 := httptest.NewRequest(http.MethodPost, "/internal/v1/peers", bytes.NewReader(bodyBytes))
	req2.Header.Set("Authorization", "Bearer secret-token")
	rr2 := httptest.NewRecorder()
	handler.ServeHTTP(rr2, req2)

	if rr2.Code != http.StatusCreated {
		t.Fatalf("expected 201 Created on second attempt, got %d", rr2.Code)
	}
}
