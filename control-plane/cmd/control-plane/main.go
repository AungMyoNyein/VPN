// Package main is the VPN control-plane entrypoint.
package main

import (
	"context"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/vpn-platform/control-plane/internal/adapter"
	"github.com/vpn-platform/control-plane/internal/api"
	"github.com/vpn-platform/control-plane/internal/config"
	"github.com/vpn-platform/control-plane/internal/health"
)

func main() {
	cfg := config.Load()
	logger := slog.New(slog.NewJSONHandler(os.Stdout, &slog.HandlerOptions{Level: slog.LevelInfo}))

	fakeAdapter := adapter.NewFakeNodeAdapter()
	remoteAdapter, err := adapter.NewRemoteNodeAdapter(nil)
	if err != nil {
		logger.Warn("could not initialize remote node adapter", "error", err)
	}

	multiAdapter := adapter.NewMultiNodeAdapter(fakeAdapter, remoteAdapter)

	mux := http.NewServeMux()
	api.RegisterRoutes(mux, logger, cfg, multiAdapter)
	mux.HandleFunc("GET /internal/v1/health", health.Handler)

	srv := &http.Server{
		Addr:              cfg.HTTPAddr,
		Handler:           api.RequestIDMiddleware(logger)(mux),
		ReadHeaderTimeout: 10 * time.Second,
	}

	go func() {
		logger.Info("control-plane listening", "addr", cfg.HTTPAddr, "env", cfg.Env)
		if err := srv.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			logger.Error("server failed", "error", err)
			os.Exit(1)
		}
	}()

	stop := make(chan os.Signal, 1)
	signal.Notify(stop, syscall.SIGINT, syscall.SIGTERM)
	<-stop

	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	_ = srv.Shutdown(ctx)
	logger.Info("control-plane stopped")
}
