package main

import (
	"context"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/vpn-platform/node-agent/internal/api"
	"github.com/vpn-platform/node-agent/internal/auth"
	"github.com/vpn-platform/node-agent/internal/config"
	"github.com/vpn-platform/node-agent/internal/network"
	"github.com/vpn-platform/node-agent/internal/telemetry"
	"github.com/vpn-platform/node-agent/internal/wireguard"
)

func main() {
	cfg := config.Load()
	if err := cfg.Validate(); err != nil {
		slog.Error("invalid configuration", "error", err)
		os.Exit(1)
	}

	logger := slog.New(slog.NewJSONHandler(os.Stdout, &slog.HandlerOptions{
		Level: slog.LevelInfo,
	}))

	logger.Info("starting node-agent",
		"node_id", cfg.NodeID,
		"node_code", cfg.NodeCode,
		"version", cfg.Version,
		"wireguard_iface", cfg.WireGuardInterface,
		"mtls_enabled", cfg.MTLSEnabled,
	)

	// Initialize WireGuard Manager
	manager, err := wireguard.NewManager(cfg)
	if err != nil {
		logger.Warn("could not connect to wgctrl, falling back to mock manager", "error", err)
	}

	// Ensure interface if running in real environment
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	if err := manager.EnsureInterface(ctx); err != nil {
		logger.Warn("ensure wireguard interface reported warning", "error", err)
	}
	cancel()

	// Ensure sysctl and nftables (if running on real node with privileges)
	if err := network.EnsureIPForwarding(); err != nil {
		logger.Warn("could not set ip_forward sysctl", "error", err)
	}

	nft := network.NewNftablesManager(cfg.WireGuardInterface, cfg.WanInterface, cfg.AuthorizedPools, 9443)
	nftCtx, nftCancel := context.WithTimeout(context.Background(), 5*time.Second)
	if err := nft.EnsureRules(nftCtx); err != nil {
		logger.Warn("could not apply nftables rules", "error", err)
	}
	nftCancel()

	metrics := telemetry.NewMetricsCollector(manager)
	mux := http.NewServeMux()
	api.RegisterRoutes(mux, logger, cfg, manager, metrics)

	handler := api.RequestLoggerMiddleware(logger, metrics)(mux)

	srv := &http.Server{
		Addr:              cfg.HTTPAddr,
		Handler:           handler,
		ReadHeaderTimeout: 5 * time.Second,
	}

	if cfg.MTLSEnabled {
		tlsConfig, err := auth.SetupServerTLS(cfg)
		if err != nil {
			logger.Error("failed to setup server mTLS", "error", err)
			os.Exit(1)
		}
		srv.TLSConfig = tlsConfig
	}

	go func() {
		logger.Info("node-agent listening", "addr", cfg.HTTPAddr, "mtls", cfg.MTLSEnabled)
		var err error
		if cfg.MTLSEnabled {
			err = srv.ListenAndServeTLS("", "")
		} else {
			err = srv.ListenAndServe()
		}
		if err != nil && err != http.ErrServerClosed {
			logger.Error("server failed", "error", err)
			os.Exit(1)
		}
	}()

	stop := make(chan os.Signal, 1)
	signal.Notify(stop, syscall.SIGINT, syscall.SIGTERM)
	<-stop

	shutdownCtx, shutdownCancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer shutdownCancel()
	_ = srv.Shutdown(shutdownCtx)
	logger.Info("node-agent stopped")
}
