package auth

import (
	"crypto/tls"
	"crypto/x509"
	"fmt"
	"net/http"
	"os"

	"github.com/vpn-platform/node-agent/internal/config"
)

// SetupServerTLS creates a tls.Config for mTLS authentication.
func SetupServerTLS(cfg config.Config) (*tls.Config, error) {
	if !cfg.MTLSEnabled {
		return nil, nil
	}

	caCert, err := os.ReadFile(cfg.CACertPath)
	if err != nil {
		return nil, fmt.Errorf("failed to read CA certificate: %w", err)
	}

	caPool := x509.NewCertPool()
	if !caPool.AppendCertsFromPEM(caCert) {
		return nil, fmt.Errorf("failed to append CA certificate to pool")
	}

	cert, err := tls.LoadX509KeyPair(cfg.ServerCertPath, cfg.ServerKeyPath)
	if err != nil {
		return nil, fmt.Errorf("failed to load server certificate/key: %w", err)
	}

	return &tls.Config{
		Certificates: []tls.Certificate{cert},
		ClientAuth:   tls.RequireAndVerifyClientCert,
		ClientCAs:    caPool,
		MinVersion:   tls.VersionTLS13,
	}, nil
}

// NodeIDValidationMiddleware validates that requests targeted at this node match its configured node_id.
func NodeIDValidationMiddleware(configuredNodeID string) func(http.Handler) http.Handler {
	return func(next http.Handler) http.Handler {
		return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			nodeIDHeader := r.Header.Get("X-Node-ID")
			if nodeIDHeader != "" && nodeIDHeader != configuredNodeID {
				http.Error(w, `{"error":{"code":"NODE_ID_MISMATCH","message":"Target node ID does not match agent node ID"}}`, http.StatusForbidden)
				return
			}
			next.ServeHTTP(w, r)
		})
	}
}
