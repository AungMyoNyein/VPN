package com.vpn.mobile.tunnel

/**
 * Authoritative connection states matching Android VpnService and tunnel engine state.
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
