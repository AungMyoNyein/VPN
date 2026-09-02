package vless

import (
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
)

type xrayClient struct {
	ID    string `json:"id"`
	Email string `json:"email"`
	Flow  string `json:"flow,omitempty"`
}

type xrayConfig struct {
	Log       any `json:"log"`
	Inbounds  []struct {
		Tag             string `json:"tag"`
		Port            int    `json:"port"`
		Protocol        string `json:"protocol"`
		Settings        struct {
			Clients    []xrayClient `json:"clients"`
			Decryption string       `json:"decryption"`
		} `json:"settings"`
		StreamSettings any `json:"streamSettings"`
		Sniffing       any `json:"sniffing"`
	} `json:"inbounds"`
	Outbounds any `json:"outbounds"`
}

const defaultXrayConfigPath = "/etc/xray/config.json"

// SyncXrayConfig rewrites Xray inbound clients from the current peer store.
func (m *Manager) SyncXrayConfig(configPath string) error {
	m.mu.RLock()
	peers := make([]PeerInfo, 0, len(m.peers))
	for _, p := range m.peers {
		peers = append(peers, *p)
	}
	m.mu.RUnlock()
	return m.syncXrayFromSnapshot(peers, configPath)
}

func (m *Manager) syncXrayFromSnapshot(peers []PeerInfo, configPath ...string) error {
	path := defaultXrayConfigPath
	if len(configPath) > 0 && configPath[0] != "" {
		path = configPath[0]
	} else if env := os.Getenv("AGENT_XRAY_CONFIG_PATH"); env != "" {
		path = env
	}

	data, err := os.ReadFile(path)
	if err != nil {
		return fmt.Errorf("read xray config: %w", err)
	}

	var cfg xrayConfig
	if err := json.Unmarshal(data, &cfg); err != nil {
		return fmt.Errorf("parse xray config: %w", err)
	}

	clients := make([]xrayClient, 0, len(peers))
	for _, p := range peers {
		clients = append(clients, xrayClient{
			ID:    p.ClientUUID,
			Email: p.Email,
			Flow:  os.Getenv("AGENT_VLESS_FLOW"),
		})
	}

	if len(cfg.Inbounds) == 0 {
		return fmt.Errorf("xray config has no inbounds")
	}

	for i := range cfg.Inbounds {
		if cfg.Inbounds[i].Protocol == "vless" {
			cfg.Inbounds[i].Settings.Clients = clients
			break
		}
	}

	encoded, err := json.MarshalIndent(cfg, "", "  ")
	if err != nil {
		return err
	}

	tmp := path + ".tmp"
	if err := os.WriteFile(tmp, encoded, 0o600); err != nil {
		return err
	}
	if err := os.Rename(tmp, path); err != nil {
		return err
	}

	if unit := os.Getenv("AGENT_XRAY_SYSTEMD_UNIT"); unit != "" {
		go func() {
			cmd := exec.Command("systemctl", "restart", unit)
			_, _ = cmd.CombinedOutput()
		}()
	}

	return nil
}

func (m *Manager) persistLocked() error {
	peers := make([]PeerInfo, 0, len(m.peers))
	for _, p := range m.peers {
		peers = append(peers, *p)
	}
	storePath := m.storePath

	if err := os.MkdirAll(filepathDir(storePath), 0o700); err != nil {
		return fmt.Errorf("create vless store dir: %w", err)
	}

	data, err := json.MarshalIndent(peers, "", "  ")
	if err != nil {
		return err
	}

	tmp := storePath + ".tmp"
	if err := os.WriteFile(tmp, data, 0o600); err != nil {
		return err
	}
	if err := os.Rename(tmp, storePath); err != nil {
		return err
	}

	// Sync Xray outside the peer mutex (caller must not hold m.mu).
	snapshot := append([]PeerInfo(nil), peers...)
	go func() {
		if err := m.syncXrayFromSnapshot(snapshot); err != nil {
			fmt.Fprintf(os.Stderr, "xray sync failed: %v\n", err)
		}
	}()

	return nil
}

func filepathDir(path string) string {
	for i := len(path) - 1; i >= 0; i-- {
		if path[i] == '/' {
			return path[:i]
		}
	}
	return "."
}
