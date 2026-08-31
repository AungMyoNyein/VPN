// Package wireguard will apply peers via the WireGuard kernel interface (Phase 3).
// Phase 0/2 use the fake adapter only — do not mutate host networking here.
//
// Security (ADR-0006): this package and the node-agent HTTP surface must expose
// only narrow peer/health operations (AddPeer, UpdatePeer, RemovePeer, GetPeer,
// GetNodeHealth, GetStatistics). Do NOT add generic remote shell / execute-command
// endpoints.
package wireguard
