package com.vpn.mobile.tunnel

/**
 * Structured VLESS tunnel configuration from provisioning API.
 * Never log uuid or full configuration in production.
 */
data class VlessTunnelConfig(
    val connectionId: String,
    val serverHost: String,
    val serverPort: Int,
    val uuid: String,
    val encryption: String = "none",
    val transport: String = "tcp",
    val security: String = "tls",
    val sni: String,
    val fingerprint: String = "chrome",
    val flow: String? = null,
    val alpn: List<String> = listOf("h2", "http/1.1"),
    val dnsServers: List<String> = listOf("1.1.1.1"),
    val mtu: Int = 1400,
    val locationLabel: String = "",
    val protocolLabel: String = "VLESS"
) {
    companion object {
        fun fromMap(map: Map<String, Any?>): VlessTunnelConfig {
            val connectionId = (map["connectionId"] as? String)?.trim()
                ?: (map["peerId"] as? String)?.trim()
                ?: ""
            val serverHost = (map["serverHost"] as? String)?.trim() ?: ""
            val serverPort = (map["serverPort"] as? Number)?.toInt() ?: 443
            val uuid = (map["uuid"] as? String)?.trim() ?: ""
            val encryption = (map["encryption"] as? String)?.trim() ?: "none"
            val transport = (map["transport"] as? String)?.trim() ?: "tcp"
            val security = (map["security"] as? String)?.trim() ?: "tls"
            val sni = (map["sni"] as? String)?.trim() ?: serverHost
            val fingerprint = (map["fingerprint"] as? String)?.trim() ?: "chrome"
            val flow = (map["flow"] as? String)?.trim()?.takeIf { it.isNotEmpty() }

            @Suppress("UNCHECKED_CAST")
            val alpn = (map["alpn"] as? List<String>)?.map { it.trim() }?.filter { it.isNotEmpty() }
                ?: listOf("h2", "http/1.1")

            @Suppress("UNCHECKED_CAST")
            val dnsServers = (map["dnsServers"] as? List<String>)?.map { it.trim() }
                ?: listOf("1.1.1.1")

            val mtu = (map["mtu"] as? Number)?.toInt() ?: 1400
            val locationLabel = (map["locationLabel"] as? String)?.trim() ?: ""
            val protocolLabel = (map["protocolLabel"] as? String)?.trim() ?: "VLESS"

            return VlessTunnelConfig(
                connectionId = connectionId,
                serverHost = serverHost,
                serverPort = serverPort,
                uuid = uuid,
                encryption = encryption,
                transport = transport,
                security = security,
                sni = sni,
                fingerprint = fingerprint,
                flow = flow,
                alpn = alpn,
                dnsServers = dnsServers.ifEmpty { listOf("1.1.1.1") },
                mtu = mtu,
                locationLabel = locationLabel,
                protocolLabel = protocolLabel
            )
        }
    }

    fun validate() {
        require(connectionId.isNotBlank()) { "connectionId cannot be blank" }
        require(serverHost.isNotBlank()) { "serverHost cannot be blank" }
        require(serverPort in 1..65535) { "serverPort must be between 1 and 65535" }
        require(uuid.isNotBlank()) { "uuid cannot be blank" }
        require(security == "tls" || security == "reality") { "unsupported VLESS security: $security" }
        require(transport == "tcp") { "unsupported VLESS transport: $transport" }
        require(dnsServers.isNotEmpty()) { "at least one DNS server must be provided" }
        require(mtu in 576..9000) { "mtu must be between 576 and 9000" }
    }

    fun redactedUuid(): String {
        if (uuid.length < 8) return "********"
        return "********-****-****-****-********${uuid.takeLast(4)}"
    }

    override fun toString(): String {
        return "VlessTunnelConfig(connectionId='$connectionId', serverHost='$serverHost', serverPort=$serverPort, uuid=[REDACTED], security='$security', sni='$sni', mtu=$mtu)"
    }
}
