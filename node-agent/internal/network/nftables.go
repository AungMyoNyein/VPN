package network

import (
	"context"
	"fmt"
	"os/exec"
	"strings"
)

const VpnTableName = "vpn_platform"

// NftablesManager manages scoped nftables firewall and NAT rules for VPN platform.
type NftablesManager struct {
	wgInterface  string
	wanInterface string
	vpnPools     []string
	mgmtPort     int
}

func NewNftablesManager(wgInterface, wanInterface string, vpnPools []string, mgmtPort int) *NftablesManager {
	if mgmtPort <= 0 {
		mgmtPort = 9443
	}
	return &NftablesManager{
		wgInterface:  wgInterface,
		wanInterface: wanInterface,
		vpnPools:     vpnPools,
		mgmtPort:     mgmtPort,
	}
}

// EnsureRules applies scoped nftables rules for WireGuard routing, NAT, client isolation, and management isolation.
func (n *NftablesManager) EnsureRules(ctx context.Context) error {
	var poolElements []string
	for _, p := range n.vpnPools {
		poolElements = append(poolElements, strings.TrimSpace(p))
	}
	poolsStr := strings.Join(poolElements, ", ")
	if poolsStr == "" {
		poolsStr = "10.200.0.0/16"
	}

	ruleset := fmt.Sprintf(`
table inet %s {
    set vpn_pools {
        type ipv4_addr
        flags interval
        elements = { %s }
    }

    chain input {
        type filter hook input priority filter; policy accept;
        # Isolate management plane from VPN clients
        iifname "%s" tcp dport { %d, 22, 8081 } counter drop
    }

    chain forward {
        type filter hook forward priority filter; policy accept;
        # Client Isolation: drop direct communication between VPN peers
        iifname "%s" oifname "%s" counter drop
        # Allow client egress to WAN
        iifname "%s" oifname "%s" ip saddr @vpn_pools counter accept
        # Allow established/related returns
        iifname "%s" oifname "%s" ct state established,related counter accept
    }

    chain postrouting {
        type nat hook postrouting priority srcnat; policy accept;
        # Masquerade outbound traffic from VPN pool
        oifname "%s" ip saddr @vpn_pools counter masquerade
    }
}
`, VpnTableName, poolsStr, n.wgInterface, n.mgmtPort, n.wgInterface, n.wgInterface, n.wgInterface, n.wanInterface, n.wanInterface, n.wgInterface, n.wanInterface)

	cmd := exec.CommandContext(ctx, "nft", "-f", "-")
	cmd.Stdin = strings.NewReader(ruleset)
	if out, err := cmd.CombinedOutput(); err != nil {
		return fmt.Errorf("failed to apply nftables ruleset: %s: %w", string(out), err)
	}

	return nil
}

// FlushRules deletes only the scoped table inet vpn_platform.
func (n *NftablesManager) FlushRules(ctx context.Context) error {
	cmd := exec.CommandContext(ctx, "nft", "delete", "table", "inet", VpnTableName)
	_ = cmd.Run() // ignore if table does not exist
	return nil
}
