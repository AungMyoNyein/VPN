package com.vpn.mobile.tunnel.engine

import android.util.Log
import com.vpn.mobile.tunnel.NativeTunnelErrorCode
import com.vpn.mobile.tunnel.SessionConfig
import com.vpn.mobile.tunnel.TunnelStatistics
import com.vpn.mobile.tunnel.VpnTunnelService
import io.nekohasekai.libbox.BoxService
import io.nekohasekai.libbox.Libbox
import io.nekohasekai.libbox.SetupOptions
import java.io.File
import java.util.concurrent.Executors
import java.util.concurrent.ScheduledExecutorService
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicBoolean
import java.util.concurrent.atomic.AtomicLong

/**
 * VLESS tunnel via sing-box libbox.
 *
 * CONNECTED readiness: BoxService.Start() succeeded, TUN fd established, and
 * tun interface TX bytes increase within [READINESS_TIMEOUT_MS] (traffic entering stack).
 */
class VlessEngine : VpnEngine {
    companion object {
        private const val TAG = "VlessEngine"
        const val READINESS_TIMEOUT_MS = 30_000L
        const val ENGINE_VERSION = "sing-box-libbox-1.11.4"
    }

    private var boxService: BoxService? = null
    private var platform: SingBoxPlatformInterface? = null
    private var listener: EngineListener? = null
    private var activeConfig: SessionConfig.Vless? = null
    private val running = AtomicBoolean(false)
    private var readinessExecutor: ScheduledExecutorService? = null
    private var statsExecutor: ScheduledExecutorService? = null
    private val connectedSinceEpochMs = AtomicLong(0L)
    private val rxBytes = AtomicLong(0L)
    private val txBytes = AtomicLong(0L)
    private var tunInterfaceName: String? = null

    override fun start(service: VpnTunnelService, config: SessionConfig, listener: EngineListener) {
        val vlessConfig = (config as? SessionConfig.Vless)?.config
            ?: throw IllegalArgumentException("VlessEngine requires VLESS session config")

        this.listener = listener
        activeConfig = config as SessionConfig.Vless
        running.set(true)

        Executors.newSingleThreadExecutor().execute {
            try {
                ensureLibboxSetup(service)
                val configJson = SingBoxConfigBuilder.build(vlessConfig)
                val platformImpl = SingBoxPlatformInterface(
                    service,
                    service.applicationContext,
                    vlessConfig.dnsServers,
                )
                platform = platformImpl

                val serviceInstance = Libbox.newService(configJson, platformImpl)
                boxService = serviceInstance
                serviceInstance.start()

                tunInterfaceName = platformImpl.tunPfd?.let { "tun0" }
                startReadinessPolling(vlessConfig)
                startStatisticsPolling()
            } catch (e: Exception) {
                Log.e(TAG, "VLESS engine start failed", e)
                running.set(false)
                val code = classifyStartError(e)
                listener.onEngineFailed(code, userMessageFor(code))
                stop()
            }
        }
    }

    private fun ensureLibboxSetup(service: VpnTunnelService) {
        val base = File(service.filesDir, "sing-box")
        base.mkdirs()
        val options = SetupOptions()
        options.basePath = base.absolutePath
        options.workingPath = File(base, "working").apply { mkdirs() }.absolutePath
        options.tempPath = File(base, "tmp").apply { mkdirs() }.absolutePath
        options.fixAndroidStack = true
        Libbox.setup(options)
    }

    private fun startReadinessPolling(config: com.vpn.mobile.tunnel.VlessTunnelConfig) {
        stopReadinessPolling()
        readinessExecutor = Executors.newSingleThreadScheduledExecutor()
        val deadline = System.currentTimeMillis() + READINESS_TIMEOUT_MS
        var baselineTx = readTunTxBytes()

        readinessExecutor?.scheduleAtFixedRate({
            try {
                if (!running.get()) {
                    stopReadinessPolling()
                    return@scheduleAtFixedRate
                }
                val currentTx = readTunTxBytes()
                if (currentTx > baselineTx && boxService != null) {
                    connectedSinceEpochMs.set(System.currentTimeMillis())
                    listener?.onEngineReady(SessionConfig.Vless(config))
                    stopReadinessPolling()
                    return@scheduleAtFixedRate
                }
                if (System.currentTimeMillis() >= deadline) {
                    running.set(false)
                    listener?.onEngineFailed(
                        NativeTunnelErrorCode.VLESS_CONNECTION_TIMEOUT,
                        "VLESS tunnel readiness timed out"
                    )
                    stop()
                    stopReadinessPolling()
                }
            } catch (e: Exception) {
                Log.w(TAG, "Readiness polling error", e)
            }
        }, 1, 1, TimeUnit.SECONDS)
    }

    private fun readTunTxBytes(): Long {
        val iface = tunInterfaceName ?: return 0L
        return try {
            val txFile = File("/sys/class/net/$iface/statistics/tx_bytes")
            if (!txFile.canRead()) 0L else txFile.readText().trim().toLongOrNull() ?: 0L
        } catch (_: Exception) {
            0L
        }
    }

    private fun readTunRxBytes(): Long {
        val iface = tunInterfaceName ?: return 0L
        return try {
            val rxFile = File("/sys/class/net/$iface/statistics/rx_bytes")
            if (!rxFile.canRead()) 0L else rxFile.readText().trim().toLongOrNull() ?: 0L
        } catch (_: Exception) {
            0L
        }
    }

    private fun startStatisticsPolling() {
        stopStatisticsPolling()
        statsExecutor = Executors.newSingleThreadScheduledExecutor()
        statsExecutor?.scheduleAtFixedRate({
            if (!running.get()) return@scheduleAtFixedRate
            val rx = readTunRxBytes()
            val tx = readTunTxBytes()
            rxBytes.set(rx)
            txBytes.set(tx)
            listener?.onStatistics(
                TunnelStatistics(
                    rxBytes = rx,
                    txBytes = tx,
                    latestHandshakeEpochMs = 0L,
                    connectedSinceEpochMs = connectedSinceEpochMs.get()
                )
            )
        }, 2, 2, TimeUnit.SECONDS)
    }

    private fun stopReadinessPolling() {
        readinessExecutor?.shutdownNow()
        readinessExecutor = null
    }

    private fun stopStatisticsPolling() {
        statsExecutor?.shutdownNow()
        statsExecutor = null
    }

    override fun stop() {
        running.set(false)
        stopReadinessPolling()
        stopStatisticsPolling()
        Executors.newSingleThreadExecutor().execute {
            try {
                boxService?.close()
            } catch (e: Exception) {
                Log.e(TAG, "Error closing sing-box service", e)
            } finally {
                platform?.closeTun()
                platform?.closeDefaultInterfaceMonitor(null)
                boxService = null
                platform = null
                activeConfig = null
                connectedSinceEpochMs.set(0L)
                rxBytes.set(0L)
                txBytes.set(0L)
                tunInterfaceName = null
            }
        }
    }

    override fun isRunning(): Boolean = running.get()

    override fun getStatistics(): TunnelStatistics {
        return TunnelStatistics(
            rxBytes = rxBytes.get(),
            txBytes = txBytes.get(),
            latestHandshakeEpochMs = 0L,
            connectedSinceEpochMs = connectedSinceEpochMs.get()
        )
    }

    private fun classifyStartError(e: Exception): String {
        val msg = (e.message ?: "").lowercase()
        return when {
            msg.contains("tls") || msg.contains("certificate") || msg.contains("x509") ->
                NativeTunnelErrorCode.VLESS_TLS_FAILED
            msg.contains("dns") -> NativeTunnelErrorCode.VLESS_DNS_FAILED
            msg.contains("tun") || msg.contains("vpn") -> NativeTunnelErrorCode.VLESS_TUN_FAILED
            msg.contains("auth") || msg.contains("rejected") -> NativeTunnelErrorCode.VLESS_AUTH_FAILED
            msg.contains("unreachable") || msg.contains("connection refused") ->
                NativeTunnelErrorCode.VLESS_SERVER_UNREACHABLE
            else -> NativeTunnelErrorCode.VLESS_ENGINE_START_FAILED
        }
    }

    private fun userMessageFor(code: String): String = when (code) {
        NativeTunnelErrorCode.VLESS_TLS_FAILED -> "VLESS TLS validation failed"
        NativeTunnelErrorCode.VLESS_DNS_FAILED -> "VLESS DNS configuration failed"
        NativeTunnelErrorCode.VLESS_TUN_FAILED -> "Failed to create VPN tunnel interface"
        NativeTunnelErrorCode.VLESS_AUTH_FAILED -> "VLESS authentication failed"
        NativeTunnelErrorCode.VLESS_SERVER_UNREACHABLE -> "VLESS server is unreachable"
        NativeTunnelErrorCode.VLESS_CONNECTION_TIMEOUT -> "VLESS tunnel connection timed out"
        else -> "Failed to start VLESS engine"
    }
}
