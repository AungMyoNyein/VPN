package com.vpn.mobile.tunnel.engine

import com.vpn.mobile.tunnel.VlessTunnelConfig
import org.json.JSONArray
import org.json.JSONObject

/**
 * Builds sing-box JSON configuration for VLESS over TLS (IPv4-only full tunnel).
 */
object SingBoxConfigBuilder {
    // /24 gives sing-box a hijack DNS address (172.19.0.2) via getDNSServerAddress().
    private const val TUN_IPV4 = "172.19.0.1/24"

    fun build(config: VlessTunnelConfig): String {
        require(config.security == "tls") { "Only TLS VLESS is supported" }

        val outbound = JSONObject().apply {
            put("type", "vless")
            put("tag", "proxy")
            put("server", config.serverHost)
            put("server_port", config.serverPort)
            put("uuid", config.uuid)
            put("network", config.transport)
            put("domain_strategy", "prefer_ipv4")
            // Plain TCP+TLS must not send XTLS flow — breaks Xray freedom forwarding.
            val flow = config.flow?.trim()
            if (!flow.isNullOrEmpty() && config.security != "tls") {
                put("flow", flow)
            }
            put("tls", JSONObject().apply {
                put("enabled", true)
                put("server_name", config.sni)
                put("insecure", false)
                put("utls", JSONObject().apply {
                    put("enabled", true)
                    put("fingerprint", config.fingerprint)
                })
                if (config.alpn.isNotEmpty()) {
                    put("alpn", JSONArray(config.alpn))
                }
            })
        }

        val dnsServers = JSONArray().apply {
            put(JSONObject().apply {
                put("tag", "dns-direct")
                put("address", "local")
                put("detour", "direct")
            })
            for (dns in config.dnsServers) {
                put(JSONObject().apply {
                    put("tag", "dns-$dns")
                    put("address", dns)
                    put("detour", "proxy")
                })
            }
        }

        val routeRules = JSONArray().apply {
            put(JSONObject().apply {
                put("protocol", "dns")
                put("outbound", "dns-out")
            })
            put(JSONObject().apply {
                put("ip_is_private", true)
                put("outbound", "direct")
            })
        }

        val root = JSONObject().apply {
            put("log", JSONObject().apply {
                put("level", "warn")
                put("timestamp", true)
            })
            put("dns", JSONObject().apply {
                put("servers", dnsServers)
                put("final", "dns-${config.dnsServers.firstOrNull() ?: "1.1.1.1"}")
                put("strategy", "prefer_ipv4")
            })
            put("inbounds", JSONArray().apply {
                put(JSONObject().apply {
                    put("type", "tun")
                    put("tag", "tun-in")
                    put("interface_name", "tun0")
                    put("inet4_address", TUN_IPV4)
                    put("mtu", config.mtu)
                    put("auto_route", true)
                    put("strict_route", true)
                    put("stack", "mixed")
                    put("sniff", true)
                    put("sniff_override_destination", true)
                })
            })
            put("outbounds", JSONArray().apply {
                put(outbound)
                put(JSONObject().apply {
                    put("type", "dns")
                    put("tag", "dns-out")
                })
                put(JSONObject().apply {
                    put("type", "direct")
                    put("tag", "direct")
                })
                put(JSONObject().apply {
                    put("type", "block")
                    put("tag", "block")
                })
            })
            put("route", JSONObject().apply {
                put("rules", routeRules)
                put("final", "proxy")
                put("auto_detect_interface", true)
            })
        }

        return root.toString()
    }
}
