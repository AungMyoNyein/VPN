package com.vpn.mobile.tunnel

import org.json.JSONArray
import org.json.JSONObject

/**
 * Validated, typed configuration model for WireGuard tunnel.
 * Note: Never log or serialize the privateKey in plain debug output.
 */
data class TunnelConfig(
    val peerId: String,
    val privateKey: String,
    val clientAddress: String, // e.g. "10.200.20.2/32"
    val serverPublicKey: String,
    val serverEndpoint: String, // e.g. "sg01.vpn.example.com:51820" or "192.168.1.1:51820"
    val dnsServers: List<String> = listOf("1.1.1.1"),
    val allowedIps: List<String> = listOf("0.0.0.0/0"),
    val persistentKeepalive: Int = 25,
    val mtu: Int = 1420,
    val allowLocalNetwork: Boolean = false,
    val blockedApplications: List<String> = emptyList(),
    val allowedApplications: List<String> = emptyList(),
    val locationLabel: String = ""
) {
    companion object {
        fun fromMap(map: Map<String, Any?>): TunnelConfig {
            val peerId = (map["peerId"] as? String)?.trim() ?: ""
            val privateKey = (map["privateKey"] as? String)?.trim() ?: ""
            val clientAddress = (map["clientAddress"] as? String)?.trim() ?: ""
            val serverPublicKey = (map["serverPublicKey"] as? String)?.trim() ?: ""
            val serverEndpoint = (map["serverEndpoint"] as? String)?.trim() ?: ""

            @Suppress("UNCHECKED_CAST")
            val dnsServers = (map["dnsServers"] as? List<String>)?.map { it.trim() }
                ?: listOf("1.1.1.1")

            @Suppress("UNCHECKED_CAST")
            val allowedIps = (map["allowedIps"] as? List<String>)?.map { it.trim() }
                ?.filter { !it.contains(":") }
                ?: listOf("0.0.0.0/0")

            val persistentKeepalive = (map["persistentKeepalive"] as? Number)?.toInt() ?: 25
            val mtu = (map["mtu"] as? Number)?.toInt() ?: 1420
            val allowLocalNetwork = (map["allowLocalNetwork"] as? Boolean) ?: false

            @Suppress("UNCHECKED_CAST")
            val blockedApps = (map["blockedApplications"] as? List<String>) ?: emptyList()

            @Suppress("UNCHECKED_CAST")
            val allowedApps = (map["allowedApplications"] as? List<String>) ?: emptyList()
            val locationLabel = (map["locationLabel"] as? String)?.trim() ?: ""

            return TunnelConfig(
                peerId = peerId,
                privateKey = privateKey,
                clientAddress = clientAddress,
                serverPublicKey = serverPublicKey,
                serverEndpoint = serverEndpoint,
                dnsServers = dnsServers.ifEmpty { listOf("1.1.1.1") },
                allowedIps = allowedIps.ifEmpty { listOf("0.0.0.0/0") },
                persistentKeepalive = persistentKeepalive,
                mtu = mtu,
                allowLocalNetwork = allowLocalNetwork,
                blockedApplications = blockedApps,
                allowedApplications = allowedApps,
                locationLabel = locationLabel
            )
        }
    }

    /**
     * Validates sanity of configuration fields before establishing tunnel.
     * Throws IllegalArgumentException if configuration is invalid.
     */
    fun validate() {
        require(peerId.isNotBlank()) { "peerId cannot be blank" }
        require(privateKey.isNotBlank()) { "privateKey cannot be blank" }
        require(serverPublicKey.isNotBlank()) { "serverPublicKey cannot be blank" }
        require(serverEndpoint.isNotBlank()) { "serverEndpoint cannot be blank" }
        require(serverEndpoint.contains(":")) { "serverEndpoint must be formatted as host:port" }
        
        val endpointParts = serverEndpoint.split(":")
        val port = endpointParts.lastOrNull()?.toIntOrNull()
        require(port != null && port in 1..65535) { "serverEndpoint port must be between 1 and 65535" }

        require(clientAddress.isNotBlank() && clientAddress.contains(".")) {
            "clientAddress must be a valid IPv4 CIDR"
        }

        require(mtu in 576..9000) { "mtu must be between 576 and 9000, got $mtu" }
        require(persistentKeepalive in 0..3600) { "persistentKeepalive must be between 0 and 3600" }
        require(dnsServers.isNotEmpty()) { "at least one DNS server must be provided" }
    }

    /**
     * Safe string representation redacting sensitive private key.
     */
    override fun toString(): String {
        return "TunnelConfig(peerId='$peerId', clientAddress='$clientAddress', serverEndpoint='$serverEndpoint', dnsServers=$dnsServers, allowedIps=$allowedIps, mtu=$mtu, persistentKeepalive=$persistentKeepalive, allowLocalNetwork=$allowLocalNetwork, privateKey=[REDACTED])"
    }
}
