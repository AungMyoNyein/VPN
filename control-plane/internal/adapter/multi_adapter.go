package adapter

import (
	"context"
	"sync"
)

// MultiNodeAdapter routes node mutations and queries to either FakeNodeAdapter or RemoteNodeAdapter based on node configuration.
type MultiNodeAdapter struct {
	mu           sync.RWMutex
	fakeAdapter  *FakeNodeAdapter
	remoteAdapter *RemoteNodeAdapter
	nodeTypes    map[string]string // nodeID -> "fake" | "remote"
}

func NewMultiNodeAdapter(fake *FakeNodeAdapter, remote *RemoteNodeAdapter) *MultiNodeAdapter {
	if fake == nil {
		fake = NewFakeNodeAdapter()
	}
	return &MultiNodeAdapter{
		fakeAdapter:   fake,
		remoteAdapter: remote,
		nodeTypes:     make(map[string]string),
	}
}

func (m *MultiNodeAdapter) SetNodeType(nodeID string, adapterType string) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.nodeTypes[nodeID] = adapterType
}

func (m *MultiNodeAdapter) getAdapter(nodeID string) NodeAdapter {
	m.mu.RLock()
	defer m.mu.RUnlock()

	adapterType, ok := m.nodeTypes[nodeID]
	if ok && adapterType == "remote" && m.remoteAdapter != nil {
		return m.remoteAdapter
	}
	return m.fakeAdapter
}

func (m *MultiNodeAdapter) AddPeer(ctx context.Context, req AddPeerRequest) error {
	return m.getAdapter(req.NodeID).AddPeer(ctx, req)
}

func (m *MultiNodeAdapter) RemovePeer(ctx context.Context, req RemovePeerRequest) error {
	return m.getAdapter(req.NodeID).RemovePeer(ctx, req)
}

func (m *MultiNodeAdapter) GetPeer(ctx context.Context, peerID string) (*PeerState, error) {
	// Try fake first
	peer, err := m.fakeAdapter.GetPeer(ctx, peerID)
	if err == nil {
		return peer, nil
	}
	if m.remoteAdapter != nil {
		return m.remoteAdapter.GetPeer(ctx, peerID)
	}
	return nil, err
}

func (m *MultiNodeAdapter) ListPeers(ctx context.Context, nodeID string) ([]PeerState, error) {
	if nodeID != "" {
		return m.getAdapter(nodeID).ListPeers(ctx, nodeID)
	}
	fakePeers, _ := m.fakeAdapter.ListPeers(ctx, "")
	var remotePeers []PeerState
	if m.remoteAdapter != nil {
		remotePeers, _ = m.remoteAdapter.ListPeers(ctx, "")
	}
	return append(fakePeers, remotePeers...), nil
}

func (m *MultiNodeAdapter) GetNode(ctx context.Context, nodeID string) (*NodeState, error) {
	return m.getAdapter(nodeID).GetNode(ctx, nodeID)
}

func (m *MultiNodeAdapter) ListNodes(ctx context.Context) ([]NodeState, error) {
	fakeNodes, _ := m.fakeAdapter.ListNodes(ctx)
	if m.remoteAdapter == nil {
		return fakeNodes, nil
	}
	remoteNodes, _ := m.remoteAdapter.ListNodes(ctx)
	return append(fakeNodes, remoteNodes...), nil
}

func (m *MultiNodeAdapter) SetDrain(ctx context.Context, nodeID string, drain bool) error {
	return m.getAdapter(nodeID).SetDrain(ctx, nodeID, drain)
}

func (m *MultiNodeAdapter) SetMaintenance(ctx context.Context, nodeID string, maintenance bool) error {
	return m.getAdapter(nodeID).SetMaintenance(ctx, nodeID, maintenance)
}

func (m *MultiNodeAdapter) Health(ctx context.Context) error {
	if err := m.fakeAdapter.Health(ctx); err != nil {
		return err
	}
	if m.remoteAdapter != nil {
		return m.remoteAdapter.Health(ctx)
	}
	return nil
}

func (m *MultiNodeAdapter) InjectFailure(action string, failureType string) {
	m.fakeAdapter.InjectFailure(action, failureType)
	if m.remoteAdapter != nil {
		m.remoteAdapter.InjectFailure(action, failureType)
	}
}

func (m *MultiNodeAdapter) ResetFailures() {
	m.fakeAdapter.ResetFailures()
	if m.remoteAdapter != nil {
		m.remoteAdapter.ResetFailures()
	}
}
