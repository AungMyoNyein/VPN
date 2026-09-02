# Ansible (optional)

For ad-hoc production installs, prefer the shell scripts in `../scripts/`:

- `install-api-cms-server.sh` — API, CMS, Postgres, Redis, control plane
- `install-vpn-node.sh` — WireGuard, node-agent, VLESS
- `register-remote-node.sh` — control plane node registration

See `../scripts/README.md` for usage examples.

The `playbooks/vpn-node.yml` playbook remains a reference; it is not required when using the install scripts.
