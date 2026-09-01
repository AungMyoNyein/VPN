package com.vpn.mobile.tunnel

enum class TunnelProtocol(val rawValue: String) {
    WIREGUARD("wireguard"),
    VLESS("vless");

    companion object {
        fun fromRaw(value: String?): TunnelProtocol {
            return entries.firstOrNull { it.rawValue.equals(value, ignoreCase = true) }
                ?: WIREGUARD
        }
    }
}

sealed class SessionConfig {
    abstract val protocol: TunnelProtocol
    abstract val locationLabel: String
    abstract val protocolLabel: String

    data class WireGuard(val config: TunnelConfig) : SessionConfig() {
        override val protocol = TunnelProtocol.WIREGUARD
        override val locationLabel: String get() = config.locationLabel
        override val protocolLabel: String = "WireGuard"
    }

    data class Vless(val config: VlessTunnelConfig) : SessionConfig() {
        override val protocol = TunnelProtocol.VLESS
        override val locationLabel: String get() = config.locationLabel
        override val protocolLabel: String get() = config.protocolLabel
    }

    companion object {
        fun fromMap(map: Map<String, Any?>): SessionConfig {
            val protocol = TunnelProtocol.fromRaw(map["protocol"] as? String)
            return when (protocol) {
                TunnelProtocol.VLESS -> Vless(VlessTunnelConfig.fromMap(map))
                TunnelProtocol.WIREGUARD -> WireGuard(TunnelConfig.fromMap(map))
            }
        }
    }
}
