package config_test

import (
	"testing"

	"github.com/vpn-platform/node-agent/internal/config"
)

func TestConfigValidation(t *testing.T) {
	cfg := config.Config{
		NodeID:             "1",
		WireGuardInterface: "wg0",
		AuthorizedPools:    []string{"10.200.20.0/24"},
		MTLSEnabled:        false,
	}

	if err := cfg.Validate(); err != nil {
		t.Fatalf("expected valid config, got: %v", err)
	}

	// Missing node id
	invalidCfg := cfg
	invalidCfg.NodeID = ""
	if err := invalidCfg.Validate(); err == nil {
		t.Fatalf("expected error for missing node_id")
	}

	// Invalid pool CIDR
	invalidPool := cfg
	invalidPool.AuthorizedPools = []string{"not-a-cidr"}
	if err := invalidPool.Validate(); err == nil {
		t.Fatalf("expected error for invalid pool CIDR")
	}

	// mTLS missing certs
	mtlsCfg := cfg
	mtlsCfg.MTLSEnabled = true
	if err := mtlsCfg.Validate(); err == nil {
		t.Fatalf("expected error for mTLS missing cert paths")
	}
}
