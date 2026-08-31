package telemetry

import (
	"fmt"
	"net/http"
	"sync/atomic"

	"github.com/vpn-platform/node-agent/internal/wireguard"
)

// MetricsCollector tracks operational metrics for the Node Agent.
type MetricsCollector struct {
	manager     wireguard.Manager
	apiRequests atomic.Uint64
	apiErrors   atomic.Uint64
}

func NewMetricsCollector(manager wireguard.Manager) *MetricsCollector {
	return &MetricsCollector{
		manager: manager,
	}
}

func (m *MetricsCollector) IncRequests() {
	m.apiRequests.Add(1)
}

func (m *MetricsCollector) IncErrors() {
	m.apiErrors.Add(1)
}

func (m *MetricsCollector) Handler() http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		dev, err := m.manager.Device(r.Context())
		ifaceUp := 1
		peersCount := 0
		activePeers := 0
		var totalRx, totalTx int64

		if err != nil {
			ifaceUp = 0
		} else if dev != nil {
			peersCount = dev.PeersCount
			activePeers = dev.ActivePeersCount
			totalRx = dev.TotalRxBytes
			totalTx = dev.TotalTxBytes
		}

		w.Header().Set("Content-Type", "text/plain; version=0.0.4; charset=utf-8")
		fmt.Fprintf(w, "# HELP vpn_node_agent_up Whether the node agent is running\n")
		fmt.Fprintf(w, "# TYPE vpn_node_agent_up gauge\n")
		fmt.Fprintf(w, "vpn_node_agent_up 1\n\n")

		fmt.Fprintf(w, "# HELP vpn_wireguard_interface_up Whether WireGuard interface is operational\n")
		fmt.Fprintf(w, "# TYPE vpn_wireguard_interface_up gauge\n")
		fmt.Fprintf(w, "vpn_wireguard_interface_up %d\n\n", ifaceUp)

		fmt.Fprintf(w, "# HELP vpn_wireguard_peers_total Total configured WireGuard peers\n")
		fmt.Fprintf(w, "# TYPE vpn_wireguard_peers_total gauge\n")
		fmt.Fprintf(w, "vpn_wireguard_peers_total %d\n\n", peersCount)

		fmt.Fprintf(w, "# HELP vpn_wireguard_recent_peers_total Active peers with handshake in last 3m\n")
		fmt.Fprintf(w, "# TYPE vpn_wireguard_recent_peers_total gauge\n")
		fmt.Fprintf(w, "vpn_wireguard_recent_peers_total %d\n\n", activePeers)

		fmt.Fprintf(w, "# HELP vpn_wireguard_rx_bytes_total Total received bytes\n")
		fmt.Fprintf(w, "# TYPE vpn_wireguard_rx_bytes_total counter\n")
		fmt.Fprintf(w, "vpn_wireguard_rx_bytes_total %d\n\n", totalRx)

		fmt.Fprintf(w, "# HELP vpn_wireguard_tx_bytes_total Total transmitted bytes\n")
		fmt.Fprintf(w, "# TYPE vpn_wireguard_tx_bytes_total counter\n")
		fmt.Fprintf(w, "vpn_wireguard_tx_bytes_total %d\n\n", totalTx)

		fmt.Fprintf(w, "# HELP vpn_node_agent_api_requests_total Total API requests\n")
		fmt.Fprintf(w, "# TYPE vpn_node_agent_api_requests_total counter\n")
		fmt.Fprintf(w, "vpn_node_agent_api_requests_total %d\n\n", m.apiRequests.Load())

		fmt.Fprintf(w, "# HELP vpn_node_agent_api_errors_total Total API error responses\n")
		fmt.Fprintf(w, "# TYPE vpn_node_agent_api_errors_total counter\n")
		fmt.Fprintf(w, "vpn_node_agent_api_errors_total %d\n", m.apiErrors.Load())
	}
}
