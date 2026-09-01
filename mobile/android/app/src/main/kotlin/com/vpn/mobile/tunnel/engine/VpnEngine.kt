package com.vpn.mobile.tunnel.engine

import com.vpn.mobile.tunnel.SessionConfig
import com.vpn.mobile.tunnel.TunnelStatistics
import com.vpn.mobile.tunnel.VpnTunnelService

interface VpnEngine {
    fun start(service: VpnTunnelService, config: SessionConfig, listener: EngineListener)
    fun stop()
    fun isRunning(): Boolean
    fun getStatistics(): TunnelStatistics
}

interface EngineListener {
    fun onEngineReady(config: SessionConfig)
    fun onEngineFailed(code: String, message: String)
    fun onStatistics(stats: TunnelStatistics)
}
