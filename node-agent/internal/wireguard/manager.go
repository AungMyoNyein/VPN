package wireguard

import (
	"context"
	"fmt"
	"net"
	"os/exec"
	"strings"
	"sync"
	"time"

	"github.com/vpn-platform/node-agent/internal/config"
	"golang.zx2c4.com/wireguard/wgctrl"
	"golang.zx2c4.com/wireguard/wgctrl/wgtypes"
)

// Manager defines the interface for WireGuard interface and peer operations.
type Manager interface {
	AddPeer(ctx context.Context, peerID string, publicKey string, allowedIP string) error
	UpdatePeer(ctx context.Context, peerID string, publicKey string, allowedIP string) error
	RemovePeer(ctx context.Context, peerID string, publicKey string) error
	GetPeer(ctx context.Context, peerID string) (*PeerInfo, error)
	ListPeers(ctx context.Context) ([]PeerInfo, error)
	Device(ctx context.Context) (*DeviceInfo, error)
	EnsureInterface(ctx context.Context) error
	InjectFailure(action string, failureType string)
	ResetFailures()
}

// RealWireguardManager communicates with the Linux kernel WireGuard interface via wgctrl.
type RealWireguardManager struct {
	cfg        config.Config
	mu         sync.RWMutex
	peerMap    map[string]string    // peerID -> publicKey
	idMap      map[string]string    // publicKey -> peerID
	metaMap    map[string]*PeerInfo // peerID -> PeerInfo
	failures   map[string]string    // action -> failureType (for testing)
	client     *wgctrl.Client
	mockDevice bool                 // true if operating in mocked mode for testing without wg interface
	mockPeers  map[string]*PeerInfo
}

// NewManager creates a new WireGuard manager instance.
func NewManager(cfg config.Config) (*RealWireguardManager, error) {
	client, err := wgctrl.New()
	mock := false
	if err != nil {
		mock = true
	}

	m := &RealWireguardManager{
		cfg:        cfg,
		peerMap:    make(map[string]string),
		idMap:      make(map[string]string),
		metaMap:    make(map[string]*PeerInfo),
		failures:   make(map[string]string),
		client:     client,
		mockDevice: mock,
		mockPeers:  make(map[string]*PeerInfo),
	}

	return m, nil
}

// NewMockManager creates a purely in-memory WireGuard manager for testing.
func NewMockManager(cfg config.Config) *RealWireguardManager {
	return &RealWireguardManager{
		cfg:        cfg,
		peerMap:    make(map[string]string),
		idMap:      make(map[string]string),
		metaMap:    make(map[string]*PeerInfo),
		failures:   make(map[string]string),
		client:     nil,
		mockDevice: true,
		mockPeers:  make(map[string]*PeerInfo),
	}
}

func (m *RealWireguardManager) checkFailure(action string) error {
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

func (m *RealWireguardManager) InjectFailure(action string, failureType string) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.failures[action] = failureType
}

func (m *RealWireguardManager) ResetFailures() {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.failures = make(map[string]string)
}

// ValidateIPInPool verifies that the assigned IP falls strictly inside one of the authorized CIDR pools.
func (m *RealWireguardManager) ValidateIPInPool(ipStr string) (*net.IPNet, error) {
	cleanIP := strings.TrimSpace(ipStr)
	if !strings.Contains(cleanIP, "/") {
		cleanIP = cleanIP + "/32"
	}

	ip, ipNet, err := net.ParseCIDR(cleanIP)
	if err != nil || ip.To4() == nil {
		return nil, ErrInvalidAllowedIP
	}

	matched := false
	for _, poolCIDR := range m.cfg.AuthorizedPools {
		_, poolNet, err := net.ParseCIDR(poolCIDR)
		if err != nil {
			continue
		}
		if poolNet.Contains(ip) {
			matched = true
			break
		}
	}

	if !matched {
		return nil, ErrIPNotInPool
	}
	return ipNet, nil
}

// EnsureInterface checks if the WireGuard interface exists and configures its private key and listen port.
func (m *RealWireguardManager) EnsureInterface(ctx context.Context) error {
	if m.mockDevice {
		return nil
	}

	// Try checking if device exists
	_, err := m.client.Device(m.cfg.WireGuardInterface)
	if err != nil {
		// Interface may not exist, create it using `ip link add dev wg0 type wireguard`
		cmd := exec.CommandContext(ctx, "ip", "link", "add", "dev", m.cfg.WireGuardInterface, "type", "wireguard")
		if out, err := cmd.CombinedOutput(); err != nil && !strings.Contains(string(out), "File exists") {
			return fmt.Errorf("failed to create wireguard interface %s: %s: %w", m.cfg.WireGuardInterface, string(out), err)
		}
	}

	// Ensure private key
	privKey, _, err := EnsureKeypair(m.cfg.WireGuardPrivateKeyPath)
	if err != nil {
		return err
	}

	listenPort := m.cfg.WireGuardListenPort
	if listenPort <= 0 {
		listenPort = 51820
	}

	cfg := wgtypes.Config{
		PrivateKey:   &privKey,
		ListenPort:   &listenPort,
		ReplacePeers: false,
	}

	if err := m.client.ConfigureDevice(m.cfg.WireGuardInterface, cfg); err != nil {
		return fmt.Errorf("failed to configure wireguard device %s: %w", m.cfg.WireGuardInterface, err)
	}

	// Bring link up
	upCmd := exec.CommandContext(ctx, "ip", "link", "set", "up", "dev", m.cfg.WireGuardInterface)
	_ = upCmd.Run()

	return nil
}

func (m *RealWireguardManager) AddPeer(ctx context.Context, peerID string, publicKey string, allowedIP string) error {
	if err := m.checkFailure("add_peer"); err != nil {
		return err
	}

	if peerID == "" {
		return fmt.Errorf("peer_id is required")
	}

	parsedKey, err := ValidatePublicKey(publicKey)
	if err != nil {
		return err
	}

	ipNet, err := m.ValidateIPInPool(allowedIP)
	if err != nil {
		return err
	}

	if m.mockDevice {
		m.mu.Lock()
		defer m.mu.Unlock()
		m.peerMap[peerID] = parsedKey.String()
		m.idMap[parsedKey.String()] = peerID
		m.mockPeers[peerID] = &PeerInfo{
			PeerID:           peerID,
			PublicKey:        parsedKey.String(),
			AllowedIP:        ipNet.String(),
			AllowedIPs:       []string{ipNet.String()},
			LatestHandshakeAt: time.Time{},
			ReceiveBytes:     0,
			TransmitBytes:    0,
			CreatedAt:        time.Now(),
		}
		return nil
	}

	peerCfg := wgtypes.PeerConfig{
		PublicKey:         parsedKey,
		Remove:            false,
		UpdateOnly:        false,
		ReplaceAllowedIPs: true,
		AllowedIPs:        []net.IPNet{*ipNet},
	}

	cfg := wgtypes.Config{
		Peers: []wgtypes.PeerConfig{peerCfg},
	}

	if err := m.client.ConfigureDevice(m.cfg.WireGuardInterface, cfg); err != nil {
		return fmt.Errorf("failed to configure peer in wireguard device: %w", err)
	}

	m.mu.Lock()
	m.peerMap[peerID] = parsedKey.String()
	m.idMap[parsedKey.String()] = peerID
	m.metaMap[peerID] = &PeerInfo{
		PeerID:     peerID,
		PublicKey:  parsedKey.String(),
		AllowedIP:  ipNet.String(),
		AllowedIPs: []string{ipNet.String()},
		CreatedAt:  time.Now(),
	}
	m.mu.Unlock()

	return nil
}

func (m *RealWireguardManager) UpdatePeer(ctx context.Context, peerID string, publicKey string, allowedIP string) error {
	return m.AddPeer(ctx, peerID, publicKey, allowedIP)
}

func (m *RealWireguardManager) RemovePeer(ctx context.Context, peerID string, publicKey string) error {
	if err := m.checkFailure("remove_peer"); err != nil {
		return err
	}

	var pubKeyStr string
	m.mu.RLock()
	if publicKey != "" {
		pubKeyStr = publicKey
	} else if k, ok := m.peerMap[peerID]; ok {
		pubKeyStr = k
	}
	m.mu.RUnlock()

	if pubKeyStr == "" {
		// Already absent / unknown -> idempotent success
		return nil
	}

	parsedKey, err := ValidatePublicKey(pubKeyStr)
	if err != nil {
		return err
	}

	if m.mockDevice {
		m.mu.Lock()
		defer m.mu.Unlock()
		delete(m.mockPeers, peerID)
		delete(m.peerMap, peerID)
		delete(m.idMap, parsedKey.String())
		return nil
	}

	peerCfg := wgtypes.PeerConfig{
		PublicKey: parsedKey,
		Remove:    true,
	}

	cfg := wgtypes.Config{
		Peers: []wgtypes.PeerConfig{peerCfg},
	}

	if err := m.client.ConfigureDevice(m.cfg.WireGuardInterface, cfg); err != nil {
		// If wireguard returns error that peer wasn't present, treat as idempotent success
		if strings.Contains(err.Error(), "no such") || strings.Contains(err.Error(), "not found") {
			m.mu.Lock()
			delete(m.peerMap, peerID)
			delete(m.idMap, parsedKey.String())
			delete(m.metaMap, peerID)
			m.mu.Unlock()
			return nil
		}
		return fmt.Errorf("failed to remove peer from wireguard: %w", err)
	}

	m.mu.Lock()
	delete(m.peerMap, peerID)
	delete(m.idMap, parsedKey.String())
	delete(m.metaMap, peerID)
	m.mu.Unlock()

	return nil
}

func (m *RealWireguardManager) GetPeer(ctx context.Context, peerID string) (*PeerInfo, error) {
	if err := m.checkFailure("get_peer"); err != nil {
		return nil, err
	}

	m.mu.RLock()
	pubKeyStr, exists := m.peerMap[peerID]
	mockPeer, mockExists := m.mockPeers[peerID]
	m.mu.RUnlock()

	if m.mockDevice {
		if !mockExists {
			return nil, ErrPeerNotFound
		}
		return mockPeer, nil
	}

	if !exists {
		return nil, ErrPeerNotFound
	}

	parsedKey, err := ValidatePublicKey(pubKeyStr)
	if err != nil {
		return nil, err
	}

	dev, err := m.client.Device(m.cfg.WireGuardInterface)
	if err != nil {
		return nil, fmt.Errorf("failed to query wireguard device: %w", err)
	}

	for _, p := range dev.Peers {
		if p.PublicKey == parsedKey {
			var allowedIPs []string
			var primaryIP string
			for _, ipNet := range p.AllowedIPs {
				allowedIPs = append(allowedIPs, ipNet.String())
				if primaryIP == "" {
					primaryIP = ipNet.String()
				}
			}

			var endpoint string
			if p.Endpoint != nil {
				endpoint = p.Endpoint.String()
			}

			return &PeerInfo{
				PeerID:           peerID,
				PublicKey:        p.PublicKey.String(),
				AllowedIP:        primaryIP,
				AllowedIPs:       allowedIPs,
				Endpoint:         endpoint,
				LatestHandshakeAt: p.LastHandshakeTime,
				ReceiveBytes:     p.ReceiveBytes,
				TransmitBytes:    p.TransmitBytes,
			}, nil
		}
	}

	return nil, ErrPeerNotFound
}

func (m *RealWireguardManager) ListPeers(ctx context.Context) ([]PeerInfo, error) {
	if err := m.checkFailure("list_peers"); err != nil {
		return nil, err
	}

	if m.mockDevice {
		m.mu.RLock()
		defer m.mu.RUnlock()
		result := make([]PeerInfo, 0, len(m.mockPeers))
		for _, p := range m.mockPeers {
			result = append(result, *p)
		}
		return result, nil
	}

	dev, err := m.client.Device(m.cfg.WireGuardInterface)
	if err != nil {
		return nil, fmt.Errorf("failed to query wireguard device: %w", err)
	}

	m.mu.RLock()
	defer m.mu.RUnlock()

	result := make([]PeerInfo, 0, len(dev.Peers))
	for _, p := range dev.Peers {
		keyStr := p.PublicKey.String()
		peerID, ok := m.idMap[keyStr]
		if !ok {
			peerID = "unmanaged-" + keyStr[:8]
		}

		var allowedIPs []string
		var primaryIP string
		for _, ipNet := range p.AllowedIPs {
			allowedIPs = append(allowedIPs, ipNet.String())
			if primaryIP == "" {
				primaryIP = ipNet.String()
			}
		}

		var endpoint string
		if p.Endpoint != nil {
			endpoint = p.Endpoint.String()
		}

		result = append(result, PeerInfo{
			PeerID:           peerID,
			PublicKey:        keyStr,
			AllowedIP:        primaryIP,
			AllowedIPs:       allowedIPs,
			Endpoint:         endpoint,
			LatestHandshakeAt: p.LastHandshakeTime,
			ReceiveBytes:     p.ReceiveBytes,
			TransmitBytes:    p.TransmitBytes,
		})
	}

	return result, nil
}

func (m *RealWireguardManager) Device(ctx context.Context) (*DeviceInfo, error) {
	if err := m.checkFailure("device"); err != nil {
		return nil, err
	}

	if m.mockDevice {
		m.mu.RLock()
		defer m.mu.RUnlock()
		return &DeviceInfo{
			InterfaceName:    m.cfg.WireGuardInterface,
			PublicKey:        "mock-public-key-base64=",
			ListenPort:       m.cfg.WireGuardListenPort,
			PeersCount:       len(m.mockPeers),
			ActivePeersCount: 0,
			TotalRxBytes:     0,
			TotalTxBytes:     0,
			LastQueriedAt:    time.Now(),
		}, nil
	}

	dev, err := m.client.Device(m.cfg.WireGuardInterface)
	if err != nil {
		return nil, fmt.Errorf("failed to query wireguard device: %w", err)
	}

	var totalRx, totalTx int64
	activePeers := 0
	recentThreshold := time.Now().Add(-3 * time.Minute)

	for _, p := range dev.Peers {
		totalRx += p.ReceiveBytes
		totalTx += p.TransmitBytes
		if !p.LastHandshakeTime.IsZero() && p.LastHandshakeTime.After(recentThreshold) {
			activePeers++
		}
	}

	return &DeviceInfo{
		InterfaceName:    dev.Name,
		PublicKey:        dev.PublicKey.String(),
		ListenPort:       dev.ListenPort,
		PeersCount:       len(dev.Peers),
		ActivePeersCount: activePeers,
		TotalRxBytes:     totalRx,
		TotalTxBytes:     totalTx,
		LastQueriedAt:    time.Now(),
	}, nil
}
