package vless

import (
	"context"
	"encoding/json"
	"errors"
	"os"
	"sync"
	"time"
)

var (
	ErrPeerNotFound       = errors.New("peer not found")
	ErrInvalidUUID        = errors.New("invalid vless uuid")
	ErrSimulatedTimeout   = errors.New("simulated timeout")
	ErrSimulatedError     = errors.New("simulated error")
)

// PeerInfo represents a provisioned VLESS client.
type PeerInfo struct {
	PeerID      string    `json:"peer_id"`
	ClientUUID  string    `json:"client_uuid"`
	Email       string    `json:"email,omitempty"`
	CreatedAt   time.Time `json:"created_at"`
	Protocol    string    `json:"protocol"`
}

// Manager manages VLESS users on the node (persisted locally, synced to Xray).
type Manager struct {
	mu         sync.RWMutex
	peers      map[string]*PeerInfo
	uuidToPeer map[string]string
	storePath  string
	failures   map[string]string
}

// NewManager creates a VLESS peer manager.
func NewManager(storePath string) (*Manager, error) {
	if storePath == "" {
		storePath = "/var/lib/vpn-platform/vless-peers.json"
	}

	m := &Manager{
		peers:      make(map[string]*PeerInfo),
		uuidToPeer: make(map[string]string),
		storePath:  storePath,
		failures:   make(map[string]string),
	}

	if err := m.load(); err != nil {
		return nil, err
	}

	return m, nil
}

func (m *Manager) checkFailure(action string) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	if ft, ok := m.failures[action]; ok {
		delete(m.failures, action)
		if ft == "timeout" {
			return ErrSimulatedTimeout
		}
		return ErrSimulatedError
	}
	return nil
}

func (m *Manager) InjectFailure(action string, failureType string) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.failures[action] = failureType
}

func (m *Manager) ResetFailures() {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.failures = make(map[string]string)
}

// AddPeer registers a VLESS client UUID.
func (m *Manager) AddPeer(ctx context.Context, peerID, clientUUID string) error {
	if err := m.checkFailure("add_peer"); err != nil {
		return err
	}

	if peerID == "" || clientUUID == "" {
		return ErrInvalidUUID
	}

	m.mu.Lock()
	defer m.mu.Unlock()

	info := &PeerInfo{
		PeerID:     peerID,
		ClientUUID: clientUUID,
		Email:      peerID + "@vpn-platform",
		CreatedAt:  time.Now().UTC(),
		Protocol:   "vless",
	}
	m.peers[peerID] = info
	m.uuidToPeer[clientUUID] = peerID

	return m.persistLocked()
}

// RemovePeer removes a VLESS client.
func (m *Manager) RemovePeer(ctx context.Context, peerID string) error {
	if err := m.checkFailure("remove_peer"); err != nil {
		return err
	}

	m.mu.Lock()
	defer m.mu.Unlock()

	peer, ok := m.peers[peerID]
	if !ok {
		return ErrPeerNotFound
	}

	delete(m.uuidToPeer, peer.ClientUUID)
	delete(m.peers, peerID)

	return m.persistLocked()
}

// GetPeer returns peer info by peer ID.
func (m *Manager) GetPeer(ctx context.Context, peerID string) (*PeerInfo, error) {
	m.mu.RLock()
	defer m.mu.RUnlock()
	peer, ok := m.peers[peerID]
	if !ok {
		return nil, ErrPeerNotFound
	}
	copy := *peer
	return &copy, nil
}

// ListPeers returns all VLESS peers.
func (m *Manager) ListPeers(ctx context.Context) ([]PeerInfo, error) {
	m.mu.RLock()
	defer m.mu.RUnlock()
	out := make([]PeerInfo, 0, len(m.peers))
	for _, p := range m.peers {
		out = append(out, *p)
	}
	return out, nil
}

func (m *Manager) load() error {
	data, err := os.ReadFile(m.storePath)
	if err != nil {
		if os.IsNotExist(err) {
			return nil
		}
		return err
	}

	var peers []PeerInfo
	if err := json.Unmarshal(data, &peers); err != nil {
		return err
	}

	m.mu.Lock()
	defer m.mu.Unlock()
	for _, p := range peers {
		copy := p
		m.peers[p.PeerID] = &copy
		m.uuidToPeer[p.ClientUUID] = p.PeerID
	}
	return nil
}

// Health returns peer count for health checks.
func (m *Manager) Health(ctx context.Context) (int, error) {
	m.mu.RLock()
	defer m.mu.RUnlock()
	return len(m.peers), nil
}
