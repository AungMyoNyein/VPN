package api

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"log/slog"
	"net/http"
	"time"

	"github.com/vpn-platform/node-agent/internal/telemetry"
)

type contextKey string

const (
	RequestIDKey contextKey = "request_id"
)

func RequestIDFrom(ctx context.Context) string {
	if v, ok := ctx.Value(RequestIDKey).(string); ok {
		return v
	}
	return ""
}

func RequestLoggerMiddleware(logger *slog.Logger, metrics *telemetry.MetricsCollector) func(http.Handler) http.Handler {
	return func(next http.Handler) http.Handler {
		return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			reqID := r.Header.Get("X-Request-ID")
			if reqID == "" {
				b := make([]byte, 16)
				_, _ = rand.Read(b)
				reqID = hex.EncodeToString(b)
			}

			w.Header().Set("X-Request-ID", reqID)
			ctx := context.WithValue(r.Context(), RequestIDKey, reqID)

			metrics.IncRequests()
			start := time.Now()

			next.ServeHTTP(w, r.WithContext(ctx))

			logger.Info("node-agent api request",
				"method", r.Method,
				"path", r.URL.Path,
				"request_id", reqID,
				"duration_ms", time.Since(start).Milliseconds(),
			)
		})
	}
}
