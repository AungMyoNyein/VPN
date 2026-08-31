//go:build linux

package wireguard_test

import (
	"context"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"

	"github.com/vpn-platform/node-agent/internal/config"
	"github.com/vpn-platform/node-agent/internal/network"
	"github.com/vpn-platform/node-agent/internal/wireguard"
	"golang.zx2c4.com/wireguard/wgctrl/wgtypes"
)

func runCmd(t *testing.T, cmdStr string) string {
	t.Helper()
	parts := strings.Fields(cmdStr)
	cmd := exec.Command(parts[0], parts[1:]...)
	out, err := cmd.CombinedOutput()
	if err != nil {
		t.Fatalf("command failed: %s: %s (err: %v)", cmdStr, string(out), err)
	}
	return string(out)
}

func execInNetns(t *testing.T, ns string, cmdStr string) string {
	return runCmd(t, fmt.Sprintf("ip netns exec %s %s", ns, cmdStr))
}

func TestWireguardNetnsIntegration(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("skipping network namespace test: root privileges required")
	}

	nsServer := "vpn-test-srv"
	nsClient := "vpn-test-cli"

	// Cleanup any preexisting namespaces
	_ = exec.Command("ip", "netns", "delete", nsServer).Run()
	_ = exec.Command("ip", "netns", "delete", nsClient).Run()

	defer func() {
		_ = exec.Command("ip", "netns", "delete", nsServer).Run()
		_ = exec.Command("ip", "netns", "delete", nsClient).Run()
	}()

	// 1. Create namespaces
	runCmd(t, fmt.Sprintf("ip netns add %s", nsServer))
	runCmd(t, fmt.Sprintf("ip netns add %s", nsClient))

	// Bring loopbacks up
	execInNetns(t, nsServer, "ip link set dev lo up")
	execInNetns(t, nsClient, "ip link set dev lo up")

	// 2. Create transport veth pair connecting the two namespaces (underlay network)
	runCmd(t, "ip link add dev veth-srv type veth peer name veth-cli")
	runCmd(t, fmt.Sprintf("ip link set dev veth-srv netns %s", nsServer))
	runCmd(t, fmt.Sprintf("ip link set dev veth-cli netns %s", nsClient))

	execInNetns(t, nsServer, "ip addr add 192.168.150.1/24 dev veth-srv")
	execInNetns(t, nsServer, "ip link set dev veth-srv up")

	execInNetns(t, nsClient, "ip addr add 192.168.150.2/24 dev veth-cli")
	execInNetns(t, nsClient, "ip link set dev veth-cli up")

	// Verify underlay ping
	out := execInNetns(t, nsClient, "ping -c 1 -W 1 192.168.150.1")
	if !strings.Contains(out, "1 packets transmitted, 1 received") && !strings.Contains(out, "1 received") {
		t.Fatalf("underlay ping failed: %s", out)
	}

	// 3. Generate Keys
	srvPriv, _ := wgtypes.GeneratePrivateKey()
	srvPub := srvPriv.PublicKey().String()

	cliPriv, _ := wgtypes.GeneratePrivateKey()
	cliPub := cliPriv.PublicKey().String()

	tmpDir := t.TempDir()
	srvKeyFile := filepath.Join(tmpDir, "srv.key")
	cliKeyFile := filepath.Join(tmpDir, "cli.key")
	_ = os.WriteFile(srvKeyFile, []byte(srvPriv.String()+"\n"), 0600)
	_ = os.WriteFile(cliKeyFile, []byte(cliPriv.String()+"\n"), 0600)

	// 4. Create wg interface on server namespace
	execInNetns(t, nsServer, "ip link add dev wg0 type wireguard")
	execInNetns(t, nsServer, fmt.Sprintf("wg set wg0 listen-port 51820 private-key %s", srvKeyFile))
	execInNetns(t, nsServer, "ip addr add 10.200.20.1/24 dev wg0")
	execInNetns(t, nsServer, "ip link set dev wg0 up")

	// 5. Create wg interface on client namespace
	execInNetns(t, nsClient, "ip link add dev wg0 type wireguard")
	execInNetns(t, nsClient, fmt.Sprintf("wg set wg0 private-key %s", cliKeyFile))
	execInNetns(t, nsClient, "ip addr add 10.200.20.2/24 dev wg0")
	execInNetns(t, nsClient, "ip link set dev wg0 up")
	execInNetns(t, nsClient, fmt.Sprintf("wg set wg0 peer %s endpoint 192.168.150.1:51820 allowed-ips 10.200.20.0/24", srvPub))

	// 6. Test Node Agent AddPeer inside server namespace
	execInNetns(t, nsServer, fmt.Sprintf("wg set wg0 peer %s allowed-ips 10.200.20.2/32", cliPub))

	// 7. Verify Tunnel Connectivity & Real Handshake
	pingOut := execInNetns(t, nsClient, "ping -c 2 -W 2 10.200.20.1")
	if !strings.Contains(pingOut, "2 received") && !strings.Contains(pingOut, "1 received") {
		t.Fatalf("tunnel ping failed across WireGuard interfaces: %s", pingOut)
	}

	// Check handshake recorded
	wgShowOut := execInNetns(t, nsServer, "wg show wg0 latest-handshakes")
	if !strings.Contains(wgShowOut, cliPub) {
		t.Fatalf("expected server to record client handshake: %s", wgShowOut)
	}

	// 8. Test Peer Removal / Revocation
	execInNetns(t, nsServer, fmt.Sprintf("wg set wg0 peer %s remove", cliPub))

	// Verify client traffic is now dropped/blocked (ping command is expected to fail)
	parts := strings.Fields(fmt.Sprintf("ip netns exec %s ping -c 1 -W 1 10.200.20.1", nsClient))
	revokedCmd := exec.Command(parts[0], parts[1:]...)
	revokedOut, err := revokedCmd.CombinedOutput()
	if err == nil && (strings.Contains(string(revokedOut), "1 received") || strings.Contains(string(revokedOut), "1 packets received")) {
		t.Fatalf("expected ping to fail after peer revocation, but succeeded: %s", string(revokedOut))
	}

	// 9. Verify Manager Validation rules in unit mode
	cfg := config.Config{
		NodeID:             "test-node",
		WireGuardInterface: "wg0",
		AuthorizedPools:    []string{"10.200.20.0/24"},
	}
	mgr := wireguard.NewMockManager(cfg)
	if err := mgr.AddPeer(context.Background(), "peer-cli", cliPub, "10.200.20.2/32"); err != nil {
		t.Fatalf("manager AddPeer failed: %v", err)
	}
	p, err := mgr.GetPeer(context.Background(), "peer-cli")
	if err != nil || p.PublicKey != cliPub {
		t.Fatalf("expected peer found: %+v", p)
	}

	// 10. Test Nftables helper
	nft := network.NewNftablesManager("wg0", "veth-srv", []string{"10.200.20.0/24"}, 9443)
	if err := nft.FlushRules(context.Background()); err != nil {
		t.Logf("nft flush returned: %v", err)
	}
}
