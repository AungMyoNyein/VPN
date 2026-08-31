package health

import (
	"encoding/json"
	"net/http"
)

// Handler returns liveness for the control plane.
func Handler(w http.ResponseWriter, _ *http.Request) {
	w.Header().Set("Content-Type", "application/json")
	_ = json.NewEncoder(w).Encode(map[string]any{
		"data": map[string]string{"status": "ok", "service": "control-plane"},
		"meta": map[string]any{},
	})
}
