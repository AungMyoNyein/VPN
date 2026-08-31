package adapter

import (
	"context"
	"errors"
	"sync"
	"time"
)

var (
	ErrPeerNotFound       = errors.New("peer not found")
	ErrNodeNotFound       = errors.New("node not found")
	ErrNodeUnhealthy      = errors.New("node unhealthy")
	ErrNodeInMaintenance  = errors.New("node in maintenance mode")
	ErrSimulatedTimeout   = errors.New("simulated timeout")
	ErrSimulatedError     = errors.New("simulated error")
)

type AddPeerRequest struct {
	NodeID     string   `json:"node_id"`
	PeerID     string   `json:"peer_id"`
	PublicKey  string   `json:"public_key"`
	AssignedIP string   `json:"assigned_ip"`
	AllowedIPs []string `json:"allowed_ips"`
}

type RemovePeerRequest struct {
	NodeID string `json:"node_id"`
	PeerID string `json:"peer_id"`
}

type PeerState struct {
	PeerID            string    `json:"peer_id"`
	NodeID            string    `json:"node_id"`
	PublicKey         string    `json:"public_key"`
	AssignedIP        string    `json:"assigned_ip"`
	AllowedIPs        []string  `json:"allowed_ips"`
	Endpoint          string    `json:"endpoint,omitempty"`
	LatestHandshakeAt time.Time `json:"latest_handshake_at,omitempty"`
	ReceiveBytes      int64     `json:"rx_bytes"`
	TransmitBytes     int64     `json:"tx_bytes"`
	CreatedAt         time.Time `json:"created_at"`
}

type NodeState struct {
	NodeID          string    `json:"node_id"`
	HealthStatus    string    `json:"health_status"` // HEALTHY, DEGRADED, DOWN
	LifecycleStatus string    `json:"lifecycle_status"` // ACTIVE, RETIRED
	MaintenanceMode bool      `json:"maintenance_mode"`
	Draining        bool      `json:"draining"`
	ActivePeers     int       `json:"active_peers"`
	LastHeartbeatAt time.Time `json:"last_heartbeat_at"`
}

// NodeAdapter defines the contract for VPN node mutation and observation.
type NodeAdapter interface {
	AddPeer(ctx context.Context, req AddPeerRequest) error
	RemovePeer(ctx context.Context, req RemovePeerRequest) error
	GetPeer(ctx context.Context, peerID string) (*PeerState, error)
	ListPeers(ctx context.Context, nodeID string) ([]PeerState, error)
	GetNode(ctx context.Context, nodeID string) (*NodeState, error)
	ListNodes(ctx context.Context) ([]NodeState, error)
	SetDrain(ctx context.Context, nodeID string, drain bool) error
	SetMaintenance(ctx context.Context, nodeID string, maintenance bool) error
	Health(ctx context.Context) error
	InjectFailure(action string, failureType string)
	ResetFailures()
}

// FakeNodeAdapter is an in-memory thread-safe simulation of VPN node adapter for testing and Phase 3.
type FakeNodeAdapter struct {
	mu        sync.RWMutex
	peers     map[string]*PeerState
	nodes     map[string]*NodeState
	failures  map[string]string // action -> failureType
}

func NewFakeNodeAdapter() *FakeNodeAdapter {
	adapter := &FakeNodeAdapter{
		peers:    make(map[string]*PeerState),
		nodes:    make(map[string]*NodeState),
		failures: make(map[string]string),
	}

	// Initialize default simulated nodes
	adapter.nodes["1"] = &NodeState{
		NodeID:          "1",
		HealthStatus:    "HEALTHY",
		LifecycleStatus: "ACTIVE",
		MaintenanceMode: false,
		Draining:        false,
		ActivePeers:     0,
		LastHeartbeatAt: time.Now(),
	}
	adapter.nodes["2"] = &NodeState{
		NodeID:          "2",
		HealthStatus:    "HEALTHY",
		LifecycleStatus: "ACTIVE",
		MaintenanceMode: false,
		Draining:        false,
		ActivePeers:     0,
		LastHeartbeatAt: time.Now(),
	}
	adapter.nodes["3"] = &NodeState{
		NodeID:          "3",
		HealthStatus:    "HEALTHY",
		LifecycleStatus: "ACTIVE",
		MaintenanceMode: false,
		Draining:        false,
		ActivePeers:     0,
		LastHeartbeatAt: time.Now(),
	}

	return adapter
}

func (a *FakeNodeAdapter) checkFailure(action string) error {
	a.mu.Lock()
	defer a.mu.Unlock()

	if ft, ok := a.failures[action]; ok {
		delete(a.failures, action) // Consume failure injection once
		if ft == "timeout" {
			return ErrSimulatedTimeout
		}
		return ErrSimulatedError
	}
	return nil
}

func (a *FakeNodeAdapter) InjectFailure(action string, failureType string) {
	a.mu.Lock()
	defer a.mu.Unlock()
	a.failures[action] = failureType
}

func (a *FakeNodeAdapter) ResetFailures() {
	a.mu.Lock()
	defer a.mu.Unlock()
	a.failures = make(map[string]string)
}

func (a *FakeNodeAdapter) AddPeer(ctx context.Context, req AddPeerRequest) error {
	if err := a.checkFailure("add_peer"); err != nil {
		return err
	}

	a.mu.Lock()
	defer a.mu.Unlock()

	node, ok := a.nodes[req.NodeID]
	if !ok {
		// Create node state dynamically if not existing
		node = &NodeState{
			NodeID:          req.NodeID,
			HealthStatus:    "HEALTHY",
			LifecycleStatus: "ACTIVE",
			LastHeartbeatAt: time.Now(),
		}
		a.nodes[req.NodeID] = node
	}

	if node.MaintenanceMode {
		return ErrNodeInMaintenance
	}
	if node.HealthStatus != "HEALTHY" {
		return ErrNodeUnhealthy
	}

	allowedIps := req.AllowedIPs
	if len(allowedIps) == 0 {
		allowedIps = []string{"0.0.0.0/0", "::/0"}
	}

	a.peers[req.PeerID] = &PeerState{
		PeerID:     req.PeerID,
		NodeID:     req.NodeID,
		PublicKey:  req.PublicKey,
		AssignedIP: req.AssignedIP,
		AllowedIPs: allowedIps,
		CreatedAt:  time.Now(),
	}

	// Update node active peers count
	a.recalculateActivePeers(req.NodeID)
	return nil
}

func (a *FakeNodeAdapter) RemovePeer(ctx context.Context, req RemovePeerRequest) error {
	if err := a.checkFailure("remove_peer"); err != nil {
		return err
	}

	a.mu.Lock()
	defer a.mu.Unlock()

	peer, exists := a.peers[req.PeerID]
	if !exists {
		return nil // Idempotent remove
	}

	nodeID := peer.NodeID
	delete(a.peers, req.PeerID)

	if nodeID != "" {
		a.recalculateActivePeers(nodeID)
	}
	return nil
}

func (a *FakeNodeAdapter) GetPeer(ctx context.Context, peerID string) (*PeerState, error) {
	if err := a.checkFailure("get_peer"); err != nil {
		return nil, err
	}

	a.mu.RLock()
	defer a.mu.RUnlock()

	peer, ok := a.peers[peerID]
	if !ok {
		return nil, ErrPeerNotFound
	}
	return peer, nil
}

func (a *FakeNodeAdapter) ListPeers(ctx context.Context, nodeID string) ([]PeerState, error) {
	if err := a.checkFailure("list_peers"); err != nil {
		return nil, err
	}

	a.mu.RLock()
	defer a.mu.RUnlock()

	result := make([]PeerState, 0, len(a.peers))
	for _, p := range a.peers {
		if nodeID == "" || p.NodeID == nodeID {
			result = append(result, *p)
		}
	}
	return result, nil
}

func (a *FakeNodeAdapter) GetNode(ctx context.Context, nodeID string) (*NodeState, error) {
	a.mu.RLock()
	defer a.mu.RUnlock()

	node, ok := a.nodes[nodeID]
	if !ok {
		return nil, ErrNodeNotFound
	}
	return node, nil
}

func (a *FakeNodeAdapter) ListNodes(ctx context.Context) ([]NodeState, error) {
	a.mu.RLock()
	defer a.mu.RUnlock()

	result := make([]NodeState, 0, len(a.nodes))
	for _, n := range a.nodes {
		result = append(result, *n)
	}
	return result, nil
}

func (a *FakeNodeAdapter) SetDrain(ctx context.Context, nodeID string, drain bool) error {
	a.mu.Lock()
	defer a.mu.Unlock()

	node, ok := a.nodes[nodeID]
	if !ok {
		node = &NodeState{
			NodeID:          nodeID,
			HealthStatus:    "HEALTHY",
			LifecycleStatus: "ACTIVE",
			LastHeartbeatAt: time.Now(),
		}
		a.nodes[nodeID] = node
	}
	node.Draining = drain
	return nil
}

func (a *FakeNodeAdapter) SetMaintenance(ctx context.Context, nodeID string, maintenance bool) error {
	a.mu.Lock()
	defer a.mu.Unlock()

	node, ok := a.nodes[nodeID]
	if !ok {
		node = &NodeState{
			NodeID:          nodeID,
			HealthStatus:    "HEALTHY",
			LifecycleStatus: "ACTIVE",
			LastHeartbeatAt: time.Now(),
		}
		a.nodes[nodeID] = node
	}
	node.MaintenanceMode = maintenance
	return nil
}

func (a *FakeNodeAdapter) Health(ctx context.Context) error {
	if err := a.checkFailure("health"); err != nil {
		return err
	}
	return nil
}

func (a *FakeNodeAdapter) recalculateActivePeers(nodeID string) {
	count := 0
	for _, p := range a.peers {
		if p.NodeID == nodeID {
			count++
		}
	}
	if node, ok := a.nodes[nodeID]; ok {
		node.ActivePeers = count
	}
}
