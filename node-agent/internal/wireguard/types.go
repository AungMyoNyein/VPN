package wireguard

import (
	"errors"
	"time"
)

var (
	ErrPeerNotFound       = errors.New("peer not found")
	ErrInvalidPublicKey   = errors.New("invalid WireGuard public key: must be valid 32-byte Base64")
	ErrInvalidAllowedIP   = errors.New("invalid allowed_ip format: must be valid IPv4 CIDR")
	ErrIPNotInPool        = errors.New("allowed_ip does not belong to authorized IP pool for this node")
	ErrDeviceUnavailable  = errors.New("wireguard device is unavailable")
	ErrSimulatedError     = errors.New("simulated wireguard error")
	ErrSimulatedTimeout   = errors.New("simulated wireguard timeout")
)

type PeerInfo struct {
	PeerID           string    `json:"peer_id"`
	PublicKey        string    `json:"public_key"`
	AllowedIP        string    `json:"allowed_ip"`
	AllowedIPs       []string  `json:"allowed_ips"`
	Endpoint         string    `json:"endpoint,omitempty"`
	LatestHandshakeAt time.Time `json:"latest_handshake_at"`
	ReceiveBytes     int64     `json:"rx_bytes"`
	TransmitBytes    int64     `json:"tx_bytes"`
	CreatedAt        time.Time `json:"created_at"`
}

type DeviceInfo struct {
	InterfaceName   string    `json:"interface_name"`
	PublicKey       string    `json:"public_key"`
	ListenPort      int       `json:"listen_port"`
	PeersCount      int       `json:"peers_count"`
	ActivePeersCount int      `json:"active_peers_count"` // handshake < 3 minutes
	TotalRxBytes    int64     `json:"total_rx_bytes"`
	TotalTxBytes    int64     `json:"total_tx_bytes"`
	LastQueriedAt   time.Time `json:"last_queried_at"`
}
