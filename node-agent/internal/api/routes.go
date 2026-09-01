package api

import (
	"encoding/json"
	"errors"
	"log/slog"
	"net/http"
	"strings"
	"time"

	"github.com/vpn-platform/node-agent/internal/auth"
	"github.com/vpn-platform/node-agent/internal/config"
	"github.com/vpn-platform/node-agent/internal/telemetry"
	"github.com/vpn-platform/node-agent/internal/vless"
	"github.com/vpn-platform/node-agent/internal/wireguard"
)

var startTime = time.Now()

type AddPeerRequest struct {
	NodeID     string   `json:"node_id"`
	PeerID     string   `json:"peer_id"`
	Protocol   string   `json:"protocol"`
	PublicKey  string   `json:"public_key"`
	AssignedIP string   `json:"assigned_ip"`
	AllowedIPs []string `json:"allowed_ips"`
	ClientUUID string   `json:"client_uuid"`
}

func RegisterRoutes(mux *http.ServeMux, logger *slog.Logger, cfg config.Config, manager wireguard.Manager, vlessMgr *vless.Manager, metrics *telemetry.MetricsCollector) {
	nodeValidation := auth.NodeIDValidationMiddleware(cfg.NodeID)

	writeJSON := func(w http.ResponseWriter, status int, data any) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(status)
		_ = json.NewEncoder(w).Encode(data)
	}

	writeError := func(w http.ResponseWriter, status int, code, msg, reqID string) {
		metrics.IncErrors()
		writeJSON(w, status, map[string]any{
			"error": map[string]string{
				"code":       code,
				"message":    msg,
				"request_id": reqID,
			},
		})
	}

	// GET /internal/v1/health
	mux.HandleFunc("GET /internal/v1/health", func(w http.ResponseWriter, r *http.Request) {
		reqID := RequestIDFrom(r.Context())
		dev, err := manager.Device(r.Context())
		healthStatus := "HEALTHY"
		if err != nil {
			healthStatus = "DEGRADED"
		}

		writeJSON(w, http.StatusOK, map[string]any{
			"status":       healthStatus,
			"node_id":      cfg.NodeID,
			"version":      cfg.Version,
			"device_ready": err == nil,
			"uptime_sec":   int64(time.Since(startTime).Seconds()),
			"meta": map[string]string{
				"request_id": reqID,
			},
			"device": dev,
		})
	})

	// GET /internal/v1/status
	mux.HandleFunc("GET /internal/v1/status", func(w http.ResponseWriter, r *http.Request) {
		reqID := RequestIDFrom(r.Context())
		dev, _ := manager.Device(r.Context())
		writeJSON(w, http.StatusOK, map[string]any{
			"node_id":          cfg.NodeID,
			"node_code":        cfg.NodeCode,
			"version":          cfg.Version,
			"wireguard_iface":  cfg.WireGuardInterface,
			"public_endpoint":  cfg.PublicEndpoint,
			"authorized_pools": cfg.AuthorizedPools,
			"uptime_sec":       int64(time.Since(startTime).Seconds()),
			"device":           dev,
			"meta": map[string]string{
				"request_id": reqID,
			},
		})
	})

	// POST /internal/v1/peers - Add Peer
	mux.Handle("POST /internal/v1/peers", nodeValidation(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		reqID := RequestIDFrom(r.Context())
		var req AddPeerRequest
		if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
			writeError(w, http.StatusBadRequest, "INVALID_REQUEST", "Failed to parse JSON body", reqID)
			return
		}

		if req.PeerID == "" {
			writeError(w, http.StatusUnprocessableEntity, "VALIDATION_ERROR", "peer_id is required", reqID)
			return
		}

		protocol := strings.ToLower(strings.TrimSpace(req.Protocol))
		if protocol == "" {
			protocol = "wireguard"
		}

		if protocol == "vless" {
			clientUUID := strings.TrimSpace(req.ClientUUID)
			if clientUUID == "" {
				clientUUID = strings.TrimSpace(req.PublicKey)
			}
			if clientUUID == "" || vlessMgr == nil {
				writeError(w, http.StatusUnprocessableEntity, "VALIDATION_ERROR", "client_uuid is required for vless peers", reqID)
				return
			}
			if err := vlessMgr.AddPeer(r.Context(), req.PeerID, clientUUID); err != nil {
				logger.Error("failed to add vless peer", "error", err, "peer_id", req.PeerID, "request_id", reqID)
				writeError(w, http.StatusInternalServerError, "VPN_PROVISIONING_FAILED", err.Error(), reqID)
				return
			}
			logger.Info("peer added to vless", "peer_id", req.PeerID, "request_id", reqID)
			writeJSON(w, http.StatusCreated, map[string]any{
				"data": map[string]any{
					"peer_id":     req.PeerID,
					"client_uuid": clientUUID,
					"protocol":    "vless",
					"status":      "ACTIVE",
				},
				"meta": map[string]string{
					"request_id": reqID,
				},
			})
			return
		}

		if req.PublicKey == "" || req.AssignedIP == "" {
			writeError(w, http.StatusUnprocessableEntity, "VALIDATION_ERROR", "public_key and assigned_ip are required for wireguard peers", reqID)
			return
		}

		if err := manager.AddPeer(r.Context(), req.PeerID, req.PublicKey, req.AssignedIP); err != nil {
			logger.Error("failed to add peer", "error", err, "peer_id", req.PeerID, "request_id", reqID)
			code := "VPN_PROVISIONING_FAILED"
			status := http.StatusInternalServerError

			if errors.Is(err, wireguard.ErrInvalidPublicKey) {
				code = "INVALID_PUBLIC_KEY"
				status = http.StatusUnprocessableEntity
			} else if errors.Is(err, wireguard.ErrIPNotInPool) {
				code = "IP_NOT_IN_AUTHORIZED_POOL"
				status = http.StatusForbidden
			} else if errors.Is(err, wireguard.ErrInvalidAllowedIP) {
				code = "INVALID_ALLOWED_IP"
				status = http.StatusUnprocessableEntity
			} else if errors.Is(err, wireguard.ErrSimulatedTimeout) {
				code = "TIMEOUT"
				status = http.StatusGatewayTimeout
			}

			writeError(w, status, code, err.Error(), reqID)
			return
		}

		logger.Info("peer added to wireguard", "peer_id", req.PeerID, "request_id", reqID)
		writeJSON(w, http.StatusCreated, map[string]any{
			"data": map[string]any{
				"peer_id":     req.PeerID,
				"public_key":  req.PublicKey,
				"assigned_ip": req.AssignedIP,
				"status":      "ACTIVE",
			},
			"meta": map[string]string{
				"request_id": reqID,
			},
		})
	})))

	// GET /internal/v1/peers - List Peers
	mux.HandleFunc("GET /internal/v1/peers", func(w http.ResponseWriter, r *http.Request) {
		reqID := RequestIDFrom(r.Context())
		peers, err := manager.ListPeers(r.Context())
		if err != nil {
			writeError(w, http.StatusInternalServerError, "INTERNAL_ERROR", err.Error(), reqID)
			return
		}

		if vlessMgr != nil {
			vlessPeers, vErr := vlessMgr.ListPeers(r.Context())
			if vErr == nil {
				for _, p := range vlessPeers {
					peers = append(peers, wireguard.PeerInfo{
						PeerID:    p.PeerID,
						PublicKey: p.ClientUUID,
					})
				}
			}
		}

		writeJSON(w, http.StatusOK, map[string]any{
			"data": peers,
			"meta": map[string]any{
				"count":      len(peers),
				"request_id": reqID,
			},
		})
	})

	// GET /internal/v1/peers/{id} - Get Peer
	mux.HandleFunc("GET /internal/v1/peers/{id}", func(w http.ResponseWriter, r *http.Request) {
		reqID := RequestIDFrom(r.Context())
		peerID := r.PathValue("id")

		peer, err := manager.GetPeer(r.Context(), peerID)
		if err != nil {
			if vlessMgr != nil {
				if vPeer, vErr := vlessMgr.GetPeer(r.Context(), peerID); vErr == nil {
					writeJSON(w, http.StatusOK, map[string]any{
						"data": map[string]any{
							"peer_id":     vPeer.PeerID,
							"client_uuid": vPeer.ClientUUID,
							"protocol":    "vless",
						},
						"meta": map[string]string{"request_id": reqID},
					})
					return
				}
			}
			if errors.Is(err, wireguard.ErrPeerNotFound) {
				writeError(w, http.StatusNotFound, "PEER_NOT_FOUND", "Peer not found", reqID)
				return
			}
			writeError(w, http.StatusInternalServerError, "INTERNAL_ERROR", err.Error(), reqID)
			return
		}

		writeJSON(w, http.StatusOK, map[string]any{
			"data": peer,
			"meta": map[string]string{
				"request_id": reqID,
			},
		})
	})

	// PATCH /internal/v1/peers/{id} - Update Peer
	mux.Handle("PATCH /internal/v1/peers/{id}", nodeValidation(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		reqID := RequestIDFrom(r.Context())
		peerID := r.PathValue("id")

		var req struct {
			PublicKey  string `json:"public_key"`
			AssignedIP string `json:"assigned_ip"`
		}
		if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
			writeError(w, http.StatusBadRequest, "INVALID_REQUEST", "Failed to parse JSON body", reqID)
			return
		}

		if err := manager.UpdatePeer(r.Context(), peerID, req.PublicKey, req.AssignedIP); err != nil {
			writeError(w, http.StatusInternalServerError, "UPDATE_FAILED", err.Error(), reqID)
			return
		}

		writeJSON(w, http.StatusOK, map[string]any{
			"data": map[string]any{
				"peer_id": peerID,
				"updated": true,
			},
			"meta": map[string]string{
				"request_id": reqID,
			},
		})
	})))

	// DELETE /internal/v1/peers/{id} - Remove Peer (Idempotent)
	mux.Handle("DELETE /internal/v1/peers/{id}", nodeValidation(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		reqID := RequestIDFrom(r.Context())
		peerID := r.PathValue("id")
		pubKey := r.URL.Query().Get("public_key")

		if err := manager.RemovePeer(r.Context(), peerID, pubKey); err != nil {
			if vlessMgr != nil {
				if vErr := vlessMgr.RemovePeer(r.Context(), peerID); vErr == nil {
					logger.Info("peer removed from vless", "peer_id", peerID, "request_id", reqID)
					writeJSON(w, http.StatusOK, map[string]any{
						"data": map[string]any{"peer_id": peerID, "removed": true},
						"meta": map[string]string{"request_id": reqID},
					})
					return
				}
			}
			logger.Error("failed to remove peer", "error", err, "peer_id", peerID, "request_id", reqID)
			writeError(w, http.StatusInternalServerError, "REMOVE_PEER_FAILED", err.Error(), reqID)
			return
		}

		logger.Info("peer removed from wireguard", "peer_id", peerID, "request_id", reqID)
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

	// GET /internal/v1/statistics
	mux.HandleFunc("GET /internal/v1/statistics", func(w http.ResponseWriter, r *http.Request) {
		reqID := RequestIDFrom(r.Context())
		dev, err := manager.Device(r.Context())
		if err != nil {
			writeError(w, http.StatusInternalServerError, "DEVICE_ERROR", err.Error(), reqID)
			return
		}

		writeJSON(w, http.StatusOK, map[string]any{
			"data": dev,
			"meta": map[string]string{
				"request_id": reqID,
			},
		})
	})

	// Metrics endpoint
	mux.HandleFunc("GET /metrics", metrics.Handler())

	// Test failure injection (ONLY when cfg.TestMode == true)
	if cfg.TestMode {
		mux.HandleFunc("POST /internal/v1/test/inject-failure", func(w http.ResponseWriter, r *http.Request) {
			reqID := RequestIDFrom(r.Context())
			var req struct {
				Action      string `json:"action"`
				FailureType string `json:"failure_type"`
			}
			if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
				writeError(w, http.StatusBadRequest, "INVALID_REQUEST", "Invalid body", reqID)
				return
			}

			if strings.ToLower(req.Action) == "reset" {
				manager.ResetFailures()
			} else {
				manager.InjectFailure(req.Action, req.FailureType)
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
		})
	}
}
