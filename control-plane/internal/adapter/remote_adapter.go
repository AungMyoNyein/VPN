package adapter

import (
	"bytes"
	"context"
	"crypto/tls"
	"crypto/x509"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"sync"
	"time"
)

// RemoteNodeConfig holds the connection details for a remote Node Agent.
type RemoteNodeConfig struct {
	NodeID          string `json:"node_id"`
	Endpoint        string `json:"endpoint"` // e.g. "https://127.0.0.1:9443" or "http://127.0.0.1:8082"
	AdapterType     string `json:"adapter_type"` // "remote" | "fake"
	MTLSEnabled     bool   `json:"mtls_enabled"`
	CACertPath      string `json:"ca_cert_path"`
	ClientCertPath  string `json:"client_cert_path"`
	ClientKeyPath   string `json:"client_key_path"`
}

// RemoteNodeAdapter manages communication with real WireGuard Node Agents over HTTPS/mTLS.
type RemoteNodeAdapter struct {
	mu          sync.RWMutex
	nodes       map[string]*RemoteNodeConfig
	nodeStates  map[string]*NodeState
	httpClient  *http.Client
	failures    map[string]string // for testing injection
}

func NewRemoteNodeAdapter(nodes []RemoteNodeConfig) (*RemoteNodeAdapter, error) {
	tlsConfig := &tls.Config{
		MinVersion: tls.VersionTLS13,
	}

	// Build default mTLS transport if certificates provided
	if len(nodes) > 0 && nodes[0].MTLSEnabled {
		caCert, err := os.ReadFile(nodes[0].CACertPath)
		if err == nil {
			caPool := x509.NewCertPool()
			if caPool.AppendCertsFromPEM(caCert) {
				tlsConfig.RootCAs = caPool
			}
		}

		clientCert, err := tls.LoadX509KeyPair(nodes[0].ClientCertPath, nodes[0].ClientKeyPath)
		if err == nil {
			tlsConfig.Certificates = []tls.Certificate{clientCert}
		}
	}

	transport := &http.Transport{
		TLSClientConfig:     tlsConfig,
		MaxIdleConns:        100,
		MaxIdleConnsPerHost: 20,
		IdleConnTimeout:     90 * time.Second,
	}

	client := &http.Client{
		Transport: transport,
		Timeout:   5 * time.Second,
	}

	adapter := &RemoteNodeAdapter{
		nodes:      make(map[string]*RemoteNodeConfig),
		nodeStates: make(map[string]*NodeState),
		httpClient: client,
		failures:   make(map[string]string),
	}

	for _, n := range nodes {
		nc := n
		adapter.nodes[nc.NodeID] = &nc
		adapter.nodeStates[nc.NodeID] = &NodeState{
			NodeID:          nc.NodeID,
			HealthStatus:    "HEALTHY",
			LifecycleStatus: "ACTIVE",
			MaintenanceMode: false,
			Draining:        false,
			ActivePeers:     0,
			LastHeartbeatAt: time.Now(),
		}
	}

	return adapter, nil
}

func (r *RemoteNodeAdapter) RegisterNode(cfg RemoteNodeConfig) {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.nodes[cfg.NodeID] = &cfg
	if _, exists := r.nodeStates[cfg.NodeID]; !exists {
		r.nodeStates[cfg.NodeID] = &NodeState{
			NodeID:          cfg.NodeID,
			HealthStatus:    "HEALTHY",
			LifecycleStatus: "ACTIVE",
			LastHeartbeatAt: time.Now(),
		}
	}
}

func (r *RemoteNodeAdapter) checkFailure(action string) error {
	r.mu.Lock()
	defer r.mu.Unlock()

	if ft, ok := r.failures[action]; ok {
		delete(r.failures, action)
		if ft == "timeout" {
			return ErrSimulatedTimeout
		}
		return ErrSimulatedError
	}
	return nil
}

func (r *RemoteNodeAdapter) InjectFailure(action string, failureType string) {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.failures[action] = failureType
}

func (r *RemoteNodeAdapter) ResetFailures() {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.failures = make(map[string]string)
}

func (r *RemoteNodeAdapter) getNodeConfig(nodeID string) (*RemoteNodeConfig, error) {
	r.mu.RLock()
	defer r.mu.RUnlock()
	node, ok := r.nodes[nodeID]
	if !ok {
		return nil, ErrNodeNotFound
	}
	return node, nil
}

func (r *RemoteNodeAdapter) AddPeer(ctx context.Context, req AddPeerRequest) error {
	if err := r.checkFailure("add_peer"); err != nil {
		return err
	}

	nodeCfg, err := r.getNodeConfig(req.NodeID)
	if err != nil {
		return err
	}

	r.mu.RLock()
	state, exists := r.nodeStates[req.NodeID]
	r.mu.RUnlock()
	if exists {
		if state.MaintenanceMode {
			return ErrNodeInMaintenance
		}
		if state.HealthStatus != "HEALTHY" {
			return ErrNodeUnhealthy
		}
	}

	payload, err := json.Marshal(req)
	if err != nil {
		return fmt.Errorf("failed to encode add peer payload: %w", err)
	}

	url := fmt.Sprintf("%s/internal/v1/peers", nodeCfg.Endpoint)
	httpReq, err := http.NewRequestWithContext(ctx, http.MethodPost, url, bytes.NewReader(payload))
	if err != nil {
		return err
	}
	httpReq.Header.Set("Content-Type", "application/json")
	httpReq.Header.Set("X-Node-ID", req.NodeID)

	resp, err := r.httpClient.Do(httpReq)
	if err != nil {
		return fmt.Errorf("remote node add peer request failed: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode >= 400 {
		body, _ := io.ReadAll(resp.Body)
		return fmt.Errorf("remote node rejected peer (status %d): %s", resp.StatusCode, string(body))
	}

	return nil
}

func (r *RemoteNodeAdapter) RemovePeer(ctx context.Context, req RemovePeerRequest) error {
	if err := r.checkFailure("remove_peer"); err != nil {
		return err
	}

	nodeCfg, err := r.getNodeConfig(req.NodeID)
	if err != nil {
		// Idempotent if node is unknown
		return nil
	}

	url := fmt.Sprintf("%s/internal/v1/peers/%s", nodeCfg.Endpoint, req.PeerID)
	httpReq, err := http.NewRequestWithContext(ctx, http.MethodDelete, url, nil)
	if err != nil {
		return err
	}
	httpReq.Header.Set("X-Node-ID", req.NodeID)

	resp, err := r.httpClient.Do(httpReq)
	if err != nil {
		return fmt.Errorf("remote node remove peer request failed: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK && resp.StatusCode != http.StatusNotFound {
		body, _ := io.ReadAll(resp.Body)
		return fmt.Errorf("remote node remove peer error (status %d): %s", resp.StatusCode, string(body))
	}

	return nil
}

func (r *RemoteNodeAdapter) GetPeer(ctx context.Context, peerID string) (*PeerState, error) {
	if err := r.checkFailure("get_peer"); err != nil {
		return nil, err
	}

	r.mu.RLock()
	nodes := make([]*RemoteNodeConfig, 0, len(r.nodes))
	for _, n := range r.nodes {
		nodes = append(nodes, n)
	}
	r.mu.RUnlock()

	for _, n := range nodes {
		url := fmt.Sprintf("%s/internal/v1/peers/%s", n.Endpoint, peerID)
		httpReq, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
		if err != nil {
			continue
		}
		httpReq.Header.Set("X-Node-ID", n.NodeID)

		resp, err := r.httpClient.Do(httpReq)
		if err != nil || resp.StatusCode != http.StatusOK {
			if resp != nil {
				_ = resp.Body.Close()
			}
			continue
		}

		var result struct {
			Data struct {
				PeerID            string    `json:"peer_id"`
				PublicKey         string    `json:"public_key"`
				AllowedIP         string    `json:"allowed_ip"`
				AllowedIPs        []string  `json:"allowed_ips"`
				Endpoint          string    `json:"endpoint"`
				LatestHandshakeAt time.Time `json:"latest_handshake_at"`
				ReceiveBytes      int64     `json:"rx_bytes"`
				TransmitBytes     int64     `json:"tx_bytes"`
				CreatedAt         time.Time `json:"created_at"`
			} `json:"data"`
		}
		if err := json.NewDecoder(resp.Body).Decode(&result); err == nil && result.Data.PeerID != "" {
			_ = resp.Body.Close()
			return &PeerState{
				PeerID:            result.Data.PeerID,
				NodeID:            n.NodeID,
				PublicKey:         result.Data.PublicKey,
				AssignedIP:        result.Data.AllowedIP,
				AllowedIPs:        result.Data.AllowedIPs,
				Endpoint:          result.Data.Endpoint,
				LatestHandshakeAt: result.Data.LatestHandshakeAt,
				ReceiveBytes:      result.Data.ReceiveBytes,
				TransmitBytes:     result.Data.TransmitBytes,
				CreatedAt:         result.Data.CreatedAt,
			}, nil
		}
		_ = resp.Body.Close()
	}

	return nil, ErrPeerNotFound
}

func (r *RemoteNodeAdapter) ListPeers(ctx context.Context, nodeID string) ([]PeerState, error) {
	if err := r.checkFailure("list_peers"); err != nil {
		return nil, err
	}

	r.mu.RLock()
	var targets []*RemoteNodeConfig
	if nodeID != "" {
		if n, ok := r.nodes[nodeID]; ok {
			targets = append(targets, n)
		}
	} else {
		for _, n := range r.nodes {
			targets = append(targets, n)
		}
	}
	r.mu.RUnlock()

	var allPeers []PeerState
	for _, n := range targets {
		url := fmt.Sprintf("%s/internal/v1/peers", n.Endpoint)
		httpReq, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
		if err != nil {
			continue
		}
		httpReq.Header.Set("X-Node-ID", n.NodeID)

		resp, err := r.httpClient.Do(httpReq)
		if err != nil || resp.StatusCode != http.StatusOK {
			if resp != nil {
				_ = resp.Body.Close()
			}
			continue
		}

		var result struct {
			Data []struct {
				PeerID            string    `json:"peer_id"`
				PublicKey         string    `json:"public_key"`
				AllowedIP         string    `json:"allowed_ip"`
				AllowedIPs        []string  `json:"allowed_ips"`
				Endpoint          string    `json:"endpoint"`
				LatestHandshakeAt time.Time `json:"latest_handshake_at"`
				ReceiveBytes      int64     `json:"rx_bytes"`
				TransmitBytes     int64     `json:"tx_bytes"`
			} `json:"data"`
		}
		if err := json.NewDecoder(resp.Body).Decode(&result); err == nil {
			for _, p := range result.Data {
				allPeers = append(allPeers, PeerState{
					PeerID:            p.PeerID,
					NodeID:            n.NodeID,
					PublicKey:         p.PublicKey,
					AssignedIP:        p.AllowedIP,
					AllowedIPs:        p.AllowedIPs,
					Endpoint:          p.Endpoint,
					LatestHandshakeAt: p.LatestHandshakeAt,
					ReceiveBytes:      p.ReceiveBytes,
					TransmitBytes:     p.TransmitBytes,
				})
			}
		}
		_ = resp.Body.Close()
	}

	return allPeers, nil
}

func (r *RemoteNodeAdapter) GetNode(ctx context.Context, nodeID string) (*NodeState, error) {
	nodeCfg, err := r.getNodeConfig(nodeID)
	if err != nil {
		return nil, err
	}

	url := fmt.Sprintf("%s/internal/v1/health", nodeCfg.Endpoint)
	httpReq, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return nil, err
	}
	httpReq.Header.Set("X-Node-ID", nodeID)

	resp, err := r.httpClient.Do(httpReq)
	healthStatus := "HEALTHY"
	if err != nil || resp.StatusCode != http.StatusOK {
		healthStatus = "DOWN"
	}
	if resp != nil {
		_ = resp.Body.Close()
	}

	r.mu.Lock()
	defer r.mu.Unlock()
	state, exists := r.nodeStates[nodeID]
	if !exists {
		state = &NodeState{
			NodeID:          nodeID,
			HealthStatus:    healthStatus,
			LifecycleStatus: "ACTIVE",
			LastHeartbeatAt: time.Now(),
		}
		r.nodeStates[nodeID] = state
	} else {
		state.HealthStatus = healthStatus
		if healthStatus == "HEALTHY" {
			state.LastHeartbeatAt = time.Now()
		}
	}

	return state, nil
}

func (r *RemoteNodeAdapter) ListNodes(ctx context.Context) ([]NodeState, error) {
	r.mu.RLock()
	defer r.mu.RUnlock()

	result := make([]NodeState, 0, len(r.nodeStates))
	for _, s := range r.nodeStates {
		result = append(result, *s)
	}
	return result, nil
}

func (r *RemoteNodeAdapter) SetDrain(ctx context.Context, nodeID string, drain bool) error {
	r.mu.Lock()
	defer r.mu.Unlock()

	state, ok := r.nodeStates[nodeID]
	if !ok {
		return ErrNodeNotFound
	}
	state.Draining = drain
	return nil
}

func (r *RemoteNodeAdapter) SetMaintenance(ctx context.Context, nodeID string, maintenance bool) error {
	r.mu.Lock()
	defer r.mu.Unlock()

	state, ok := r.nodeStates[nodeID]
	if !ok {
		return ErrNodeNotFound
	}
	state.MaintenanceMode = maintenance
	return nil
}

func (r *RemoteNodeAdapter) Health(ctx context.Context) error {
	if err := r.checkFailure("health"); err != nil {
		return err
	}
	return nil
}
