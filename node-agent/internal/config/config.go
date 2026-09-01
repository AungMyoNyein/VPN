package config

import (
	"fmt"
	"net"
	"os"
	"strconv"
	"strings"
)

// Config holds the full configuration for the Node Agent.
type Config struct {
	NodeID                 string   `json:"node_id"`
	HTTPAddr               string   `json:"http_addr"`
	NodeCode               string   `json:"node_code"`
	WireGuardInterface     string   `json:"wireguard_interface"`
	WireGuardPrivateKeyPath string  `json:"wireguard_private_key_path"`
	WireGuardListenPort    int      `json:"wireguard_listen_port"`
	AuthorizedPools        []string `json:"authorized_pools"`
	PublicEndpoint         string   `json:"public_endpoint"`
	WanInterface           string   `json:"wan_interface"`

	// mTLS Configuration
	MTLSEnabled     bool   `json:"mtls_enabled"`
	CACertPath      string `json:"ca_cert_path"`
	ServerCertPath  string `json:"server_cert_path"`
	ServerKeyPath   string `json:"server_key_path"`
	ClientCAOrgUnit string `json:"client_ca_org_unit"`

	VlessStorePath string `json:"vless_store_path"`
	VlessEnabled   bool   `json:"vless_enabled"`

	// Operational
	LogLevel string `json:"log_level"`
	TestMode bool   `json:"test_mode"`
	Version  string `json:"version"`
}

// Load loads configuration from environment variables with sensible defaults.
func Load() Config {
	portStr := getEnv("AGENT_WG_PORT", "51820")
	port, err := strconv.Atoi(portStr)
	if err != nil {
		port = 51820
	}

	poolsStr := getEnv("AGENT_AUTHORIZED_POOLS", "10.200.0.0/16,10.200.10.0/24,10.200.20.0/24,10.200.30.0/24")
	var pools []string
	for _, p := range strings.Split(poolsStr, ",") {
		p = strings.TrimSpace(p)
		if p != "" {
			pools = append(pools, p)
		}
	}

	mtlsEnabled := getEnv("AGENT_MTLS_ENABLED", "false") == "true"
	testMode := getEnv("AGENT_TEST_MODE", "false") == "true"

	return Config{
		NodeID:                  getEnv("AGENT_NODE_ID", "1"),
		NodeCode:                getEnv("AGENT_NODE_CODE", "DEV-01"),
		HTTPAddr:                getEnv("AGENT_HTTP_ADDR", ":8082"),
		WireGuardInterface:      getEnv("AGENT_WG_IFACE", "wg0"),
		WireGuardPrivateKeyPath: getEnv("AGENT_WG_KEY_PATH", "/etc/wireguard/private.key"),
		WireGuardListenPort:     port,
		AuthorizedPools:         pools,
		PublicEndpoint:          getEnv("AGENT_PUBLIC_ENDPOINT", "127.0.0.1:51820"),
		WanInterface:            getEnv("AGENT_WAN_IFACE", "eth0"),
		MTLSEnabled:             mtlsEnabled,
		CACertPath:              getEnv("AGENT_CA_CERT_PATH", ""),
		ServerCertPath:          getEnv("AGENT_SERVER_CERT_PATH", ""),
		ServerKeyPath:           getEnv("AGENT_SERVER_KEY_PATH", ""),
		ClientCAOrgUnit:         getEnv("AGENT_CLIENT_ORG_UNIT", ""),
		LogLevel:                getEnv("AGENT_LOG_LEVEL", "info"),
		VlessStorePath:          getEnv("AGENT_VLESS_STORE_PATH", "/var/lib/vpn-platform/vless-peers.json"),
		VlessEnabled:            getEnv("AGENT_VLESS_ENABLED", "true") == "true",
		TestMode:                testMode,
		Version:                 getEnv("AGENT_VERSION", "1.0.0"),
	}
}

// Validate validates the configuration integrity.
func (c *Config) Validate() error {
	if c.NodeID == "" {
		return fmt.Errorf("node_id is required")
	}
	if c.WireGuardInterface == "" {
		return fmt.Errorf("wireguard_interface is required")
	}
	if len(c.AuthorizedPools) == 0 {
		return fmt.Errorf("at least one authorized_pool must be configured")
	}
	for _, pool := range c.AuthorizedPools {
		if _, _, err := net.ParseCIDR(pool); err != nil {
			return fmt.Errorf("invalid authorized pool CIDR %q: %w", pool, err)
		}
	}

	if c.MTLSEnabled {
		if c.CACertPath == "" || c.ServerCertPath == "" || c.ServerKeyPath == "" {
			return fmt.Errorf("mTLS enabled but ca_cert_path, server_cert_path, or server_key_path is missing")
		}
	}
	return nil
}

func getEnv(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}
