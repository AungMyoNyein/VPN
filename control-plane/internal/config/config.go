package config

import "os"

// Config holds control-plane runtime configuration.
// Secrets must come from environment / secret manager — never commit real values.
type Config struct {
	Env          string
	HTTPAddr     string
	ServiceToken string
}

func Load() Config {
	return Config{
		Env:          getEnv("CP_ENV", "local"),
		HTTPAddr:     getEnv("CP_HTTP_ADDR", ":8081"),
		ServiceToken: getEnv("CP_SERVICE_TOKEN", "dev-only-change-me"),
	}
}

func getEnv(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}
