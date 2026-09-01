package api

import (
	"encoding/json"
	"errors"
	"log/slog"
	"net/http"
	"strings"

	"github.com/vpn-platform/control-plane/internal/adapter"
	"github.com/vpn-platform/control-plane/internal/config"
)

// RegisterRoutes mounts all internal v1 API endpoints.
func RegisterRoutes(mux *http.ServeMux, logger *slog.Logger, cfg config.Config, nodeAdapter adapter.NodeAdapter) {
	auth := ServiceAuthMiddleware(cfg.ServiceToken)

	// POST /internal/v1/peers - Add Peer
	mux.Handle("POST /internal/v1/peers", auth(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		reqID := RequestIDFrom(r.Context())
		var req adapter.AddPeerRequest
		if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
			writeJSON(w, http.StatusBadRequest, map[string]any{
				"error": map[string]string{
					"code":       "VALIDATION_ERROR",
					"message":    "Invalid request payload",
					"request_id": reqID,
				},
			})
			return
		}

		if req.NodeID == "" || req.PeerID == "" {
			writeJSON(w, http.StatusUnprocessableEntity, map[string]any{
				"error": map[string]string{
					"code":       "VALIDATION_ERROR",
					"message":    "node_id and peer_id are required",
					"request_id": reqID,
				},
			})
			return
		}

		protocol := strings.ToLower(strings.TrimSpace(req.Protocol))
		if protocol == "" {
			protocol = "wireguard"
			req.Protocol = protocol
		}

		if protocol == "vless" {
			if req.ClientUUID == "" {
				req.ClientUUID = req.PublicKey
			}
			if req.ClientUUID == "" {
				writeJSON(w, http.StatusUnprocessableEntity, map[string]any{
					"error": map[string]string{
						"code":       "VALIDATION_ERROR",
						"message":    "client_uuid is required for vless peers",
						"request_id": reqID,
					},
				})
				return
			}
		} else {
			if req.PublicKey == "" || req.AssignedIP == "" {
				writeJSON(w, http.StatusUnprocessableEntity, map[string]any{
					"error": map[string]string{
						"code":       "VALIDATION_ERROR",
						"message":    "public_key and assigned_ip are required for wireguard peers",
						"request_id": reqID,
					},
				})
				return
			}
		}

		if err := nodeAdapter.AddPeer(r.Context(), req); err != nil {
			logger.Error("failed to add peer", "error", err, "request_id", reqID, "peer_id", req.PeerID)
			code := "VPN_PROVISIONING_FAILED"
			status := http.StatusInternalServerError

			if errors.Is(err, adapter.ErrNodeInMaintenance) {
				code = "NODE_MAINTENANCE"
				status = http.StatusConflict
			} else if errors.Is(err, adapter.ErrNodeUnhealthy) {
				code = "NODE_UNHEALTHY"
				status = http.StatusServiceUnavailable
			} else if errors.Is(err, adapter.ErrSimulatedTimeout) {
				code = "TIMEOUT"
				status = http.StatusGatewayTimeout
			}

			writeJSON(w, status, map[string]any{
				"error": map[string]string{
					"code":       code,
					"message":    err.Error(),
					"request_id": reqID,
				},
			})
			return
		}

		logger.Info("peer added", "peer_id", req.PeerID, "node_id", req.NodeID, "request_id", reqID)
		writeJSON(w, http.StatusCreated, map[string]any{
			"data": map[string]any{
				"peer_id":     req.PeerID,
				"node_id":     req.NodeID,
				"assigned_ip": req.AssignedIP,
				"status":      "ACTIVE",
			},
			"meta": map[string]string{
				"request_id": reqID,
			},
		})
	})))

	// GET /internal/v1/peers/{id} - Get Peer
	mux.Handle("GET /internal/v1/peers/{id}", auth(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		reqID := RequestIDFrom(r.Context())
		peerID := r.PathValue("id")

		peer, err := nodeAdapter.GetPeer(r.Context(), peerID)
		if err != nil {
			if errors.Is(err, adapter.ErrPeerNotFound) {
				writeJSON(w, http.StatusNotFound, map[string]any{
					"error": map[string]string{
						"code":       "PEER_NOT_FOUND",
						"message":    "Peer not found",
						"request_id": reqID,
					},
				})
				return
			}
			writeJSON(w, http.StatusInternalServerError, map[string]any{
				"error": map[string]string{
					"code":       "INTERNAL_ERROR",
					"message":    err.Error(),
					"request_id": reqID,
				},
			})
			return
		}

		writeJSON(w, http.StatusOK, map[string]any{
			"data": peer,
			"meta": map[string]string{
				"request_id": reqID,
			},
		})
	})))

	// DELETE /internal/v1/peers/{id} - Remove Peer
	mux.Handle("DELETE /internal/v1/peers/{id}", auth(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		reqID := RequestIDFrom(r.Context())
		peerID := r.PathValue("id")

		nodeID := r.URL.Query().Get("node_id")
		if err := nodeAdapter.RemovePeer(r.Context(), adapter.RemovePeerRequest{
			NodeID: nodeID,
			PeerID: peerID,
		}); err != nil {
			logger.Error("failed to remove peer", "error", err, "request_id", reqID, "peer_id", peerID)
			writeJSON(w, http.StatusInternalServerError, map[string]any{
				"error": map[string]string{
					"code":       "REMOVE_PEER_FAILED",
					"message":    err.Error(),
					"request_id": reqID,
				},
			})
			return
		}

		logger.Info("peer removed", "peer_id", peerID, "request_id", reqID)
		writeJSON(w, http.StatusOK, map[string]any{
			"data": map[string]any{
				"peer_id": peerID,
				"removed": true,
			},
			"meta": map[string]string{
				"request_id": reqID,
			},
		})
	})))

	// GET /internal/v1/peers - List Peers
	mux.Handle("GET /internal/v1/peers", auth(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		reqID := RequestIDFrom(r.Context())
		nodeID := r.URL.Query().Get("node_id")

		peers, err := nodeAdapter.ListPeers(r.Context(), nodeID)
		if err != nil {
			writeJSON(w, http.StatusInternalServerError, map[string]any{
				"error": map[string]string{
					"code":       "INTERNAL_ERROR",
					"message":    err.Error(),
					"request_id": reqID,
				},
			})
			return
		}

		writeJSON(w, http.StatusOK, map[string]any{
			"data": peers,
			"meta": map[string]any{
				"count":      len(peers),
				"request_id": reqID,
			},
		})
	})))

	// GET /internal/v1/nodes - List Nodes
	mux.Handle("GET /internal/v1/nodes", auth(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		reqID := RequestIDFrom(r.Context())
		nodes, err := nodeAdapter.ListNodes(r.Context())
		if err != nil {
			writeJSON(w, http.StatusInternalServerError, map[string]any{
				"error": map[string]string{
					"code":       "INTERNAL_ERROR",
					"message":    err.Error(),
					"request_id": reqID,
				},
			})
			return
		}

		writeJSON(w, http.StatusOK, map[string]any{
			"data": nodes,
			"meta": map[string]any{
				"count":      len(nodes),
				"request_id": reqID,
			},
		})
	})))

	// GET /internal/v1/nodes/{id} - Get Node
	mux.Handle("GET /internal/v1/nodes/{id}", auth(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		reqID := RequestIDFrom(r.Context())
		nodeID := r.PathValue("id")

		node, err := nodeAdapter.GetNode(r.Context(), nodeID)
		if err != nil {
			if errors.Is(err, adapter.ErrNodeNotFound) {
				writeJSON(w, http.StatusNotFound, map[string]any{
					"error": map[string]string{
						"code":       "NODE_NOT_FOUND",
						"message":    "Node not found",
						"request_id": reqID,
					},
				})
				return
			}
			writeJSON(w, http.StatusInternalServerError, map[string]any{
				"error": map[string]string{
					"code":       "INTERNAL_ERROR",
					"message":    err.Error(),
					"request_id": reqID,
				},
			})
			return
		}

		writeJSON(w, http.StatusOK, map[string]any{
			"data": node,
			"meta": map[string]string{
				"request_id": reqID,
			},
		})
	})))

	// POST /internal/v1/nodes/{id}/drain - Drain node
	mux.Handle("POST /internal/v1/nodes/{id}/drain", auth(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		reqID := RequestIDFrom(r.Context())
		nodeID := r.PathValue("id")

		var req struct {
			Drain bool `json:"drain"`
		}
		// Default to true if not specified
		req.Drain = true
		_ = json.NewDecoder(r.Body).Decode(&req)

		if err := nodeAdapter.SetDrain(r.Context(), nodeID, req.Drain); err != nil {
			writeJSON(w, http.StatusInternalServerError, map[string]any{
				"error": map[string]string{
					"code":       "DRAIN_FAILED",
					"message":    err.Error(),
					"request_id": reqID,
				},
			})
			return
		}

		logger.Info("node drain state updated", "node_id", nodeID, "draining", req.Drain, "request_id", reqID)
		writeJSON(w, http.StatusOK, map[string]any{
			"data": map[string]any{
				"node_id":  nodeID,
				"draining": req.Drain,
			},
			"meta": map[string]string{
				"request_id": reqID,
			},
		})
	})))

	// POST /internal/v1/nodes/{id}/maintenance - Maintenance mode
	mux.Handle("POST /internal/v1/nodes/{id}/maintenance", auth(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		reqID := RequestIDFrom(r.Context())
		nodeID := r.PathValue("id")

		var req struct {
			Maintenance bool `json:"maintenance"`
		}
		req.Maintenance = true
		_ = json.NewDecoder(r.Body).Decode(&req)

		if err := nodeAdapter.SetMaintenance(r.Context(), nodeID, req.Maintenance); err != nil {
			writeJSON(w, http.StatusInternalServerError, map[string]any{
				"error": map[string]string{
					"code":       "MAINTENANCE_FAILED",
					"message":    err.Error(),
					"request_id": reqID,
				},
			})
			return
		}

		logger.Info("node maintenance state updated", "node_id", nodeID, "maintenance", req.Maintenance, "request_id", reqID)
		writeJSON(w, http.StatusOK, map[string]any{
			"data": map[string]any{
				"node_id":          nodeID,
				"maintenance_mode": req.Maintenance,
			},
			"meta": map[string]string{
				"request_id": reqID,
			},
		})
	})))

	// POST /internal/v1/nodes/register - Register or update node adapter configuration
	mux.Handle("POST /internal/v1/nodes/register", auth(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		reqID := RequestIDFrom(r.Context())
		var req struct {
			NodeID      string `json:"node_id"`
			Endpoint    string `json:"endpoint"`
			AdapterType string `json:"adapter_type"` // "fake" | "remote"
			MTLSEnabled bool   `json:"mtls_enabled"`
		}
		if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
			writeJSON(w, http.StatusBadRequest, map[string]any{
				"error": map[string]string{
					"code":       "VALIDATION_ERROR",
					"message":    "Invalid registration payload",
					"request_id": reqID,
				},
			})
			return
		}

		if multi, ok := nodeAdapter.(*adapter.MultiNodeAdapter); ok {
			multi.RegisterRemoteNode(adapter.RemoteNodeConfig{
				NodeID:      req.NodeID,
				Endpoint:    req.Endpoint,
				AdapterType: req.AdapterType,
				MTLSEnabled: req.MTLSEnabled,
			})
		}

		logger.Info("node registered", "node_id", req.NodeID, "adapter_type", req.AdapterType, "request_id", reqID)
		writeJSON(w, http.StatusOK, map[string]any{
			"data": map[string]any{
				"node_id":      req.NodeID,
				"adapter_type": req.AdapterType,
				"registered":   true,
			},
			"meta": map[string]string{
				"request_id": reqID,
			},
		})
	})))

	// POST /internal/v1/test/inject-failure - Test failure injection
	mux.Handle("POST /internal/v1/test/inject-failure", auth(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		reqID := RequestIDFrom(r.Context())
		var req struct {
			Action      string `json:"action"`
			FailureType string `json:"failure_type"`
		}
		if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
			writeJSON(w, http.StatusBadRequest, map[string]any{
				"error": map[string]string{
					"code":       "VALIDATION_ERROR",
					"message":    "Invalid request",
					"request_id": reqID,
				},
			})
			return
		}

		if strings.ToLower(req.Action) == "reset" {
			nodeAdapter.ResetFailures()
		} else {
			nodeAdapter.InjectFailure(req.Action, req.FailureType)
		}

		writeJSON(w, http.StatusOK, map[string]any{
			"data": map[string]any{
				"injected": true,
				"action":   req.Action,
			},
			"meta": map[string]string{
				"request_id": reqID,
			},
		})
	})))
}
