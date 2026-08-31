package com.vpn.mobile.tunnel

/**
 * Authoritative connection states matching Android VpnService and WireGuard state engine.
 */
enum class NativeTunnelState(val rawValue: String) {
    DISCONNECTED("disconnected"),
    PREPARING("preparing"),
    AUTHORIZING("authorizing"),
    PROVISIONING("provisioning"),
    REQUESTING_PERMISSION("requesting_permission"),
    CONNECTING("connecting"),
    CONNECTED("connected"),
    RECONNECTING("reconnecting"),
    DISCONNECTING("disconnecting"),
    ERROR("error");

    companion object {
        fun fromRaw(value: String?): NativeTunnelState {
            return entries.firstOrNull { it.rawValue.equals(value, ignoreCase = true) }
                ?: DISCONNECTED
        }
    }
}

/**
 * Standardized error codes for Flutter/Kotlin native contract.
 */
object NativeTunnelErrorCode {
    const val PERMISSION_DENIED = "PERMISSION_DENIED"
    const val INVALID_CONFIG = "INVALID_CONFIG"
    const val TUNNEL_START_FAILED = "TUNNEL_START_FAILED"
    const val HANDSHAKE_TIMEOUT = "HANDSHAKE_TIMEOUT"
    const val NETWORK_LOST = "NETWORK_LOST"
    const val KEYSTORE_ERROR = "KEYSTORE_ERROR"
    const val SERVICE_UNAVAILABLE = "SERVICE_UNAVAILABLE"
    const val UNKNOWN = "UNKNOWN"
}

data class TunnelStatistics(
    val rxBytes: Long = 0L,
    val txBytes: Long = 0L,
    val latestHandshakeEpochMs: Long = 0L,
    val connectedSinceEpochMs: Long = 0L
) {
    fun toMap(): Map<String, Any> {
        return mapOf(
            "rxBytes" to rxBytes,
            "txBytes" to txBytes,
            "latestHandshakeEpochMs" to latestHandshakeEpochMs,
            "connectedSinceEpochMs" to connectedSinceEpochMs
        )
    }
}
