package network

import (
	"fmt"
	"os"
	"strings"
)

// EnsureIPForwarding enables IPv4 forwarding in the Linux kernel via /proc/sys/net/ipv4/ip_forward.
func EnsureIPForwarding() error {
	path := "/proc/sys/net/ipv4/ip_forward"
	data, err := os.ReadFile(path)
	if err != nil {
		return fmt.Errorf("failed to read %s: %w", path, err)
	}

	if strings.TrimSpace(string(data)) == "1" {
		return nil
	}

	if err := os.WriteFile(path, []byte("1\n"), 0644); err != nil {
		return fmt.Errorf("failed to enable ip_forward at %s: %w", path, err)
	}

	return nil
}
