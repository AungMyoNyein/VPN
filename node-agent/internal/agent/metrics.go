package agent

import (
	"fmt"
	"net/http"
)

// MetricsStub exposes a minimal Prometheus text format for Phase 0.
func MetricsStub(w http.ResponseWriter, _ *http.Request) {
	w.Header().Set("Content-Type", "text/plain; version=0.0.4")
	fmt.Fprintln(w, `# HELP node_agent_up Node agent process is up`)
	fmt.Fprintln(w, `# TYPE node_agent_up gauge`)
	fmt.Fprintln(w, `node_agent_up 1`)
	fmt.Fprintln(w, `# HELP node_agent_peers Peer count (stub)`)
	fmt.Fprintln(w, `# TYPE node_agent_peers gauge`)
	fmt.Fprintln(w, `node_agent_peers 0`)
}
