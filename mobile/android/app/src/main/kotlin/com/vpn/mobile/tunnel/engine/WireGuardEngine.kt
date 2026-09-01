package com.vpn.mobile.tunnel.engine

import android.util.Log
import com.vpn.mobile.tunnel.NativeTunnelErrorCode
import com.vpn.mobile.tunnel.SessionConfig
import com.vpn.mobile.tunnel.TunnelConfig
import com.vpn.mobile.tunnel.TunnelStatistics
import com.vpn.mobile.tunnel.VpnTunnelService
import com.wireguard.android.backend.GoBackend
import com.wireguard.android.backend.Tunnel
import com.wireguard.config.Config
import com.wireguard.config.InetAddresses
import com.wireguard.config.InetEndpoint
import com.wireguard.config.InetNetwork
import com.wireguard.config.Interface
import com.wireguard.config.Peer
import com.wireguard.crypto.Key
import java.util.concurrent.Executors
import java.util.concurrent.ScheduledExecutorService
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicBoolean

class WireGuardEngine : VpnEngine {
    companion object {
        private const val TAG = "WireGuardEngine"
        const val HANDSHAKE_TIMEOUT_MS = 30_000L
    }

    private var backend: GoBackend? = null
    private var activeTunnel: WireGuardTunnelInstance? = null
    private var statsExecutor: ScheduledExecutorService? = null
    private var handshakeExecutor: ScheduledExecutorService? = null
    private var listener: EngineListener? = null
    private var activeConfig: TunnelConfig? = null
    private var connectedSinceEpochMs: Long = 0L
    private val running = AtomicBoolean(false)

    override fun start(service: VpnTunnelService, config: SessionConfig, listener: EngineListener) {
        val wgConfig = (config as? SessionConfig.WireGuard)?.config
            ?: throw IllegalArgumentException("WireGuardEngine requires WireGuard session config")
        this.listener = listener
        activeConfig = wgConfig
        running.set(true)

        backend = GoBackend(service.applicationContext)

        Executors.newSingleThreadExecutor().execute {
            try {
                val wgInterfaceBuilder = Interface.Builder()
                wgInterfaceBuilder.parsePrivateKey(wgConfig.privateKey)

                val clientAddressNet = InetNetwork.parse(wgConfig.clientAddress)
                wgInterfaceBuilder.addAddress(clientAddressNet)

                for (dns in wgConfig.dnsServers) {
                    try {
                        wgInterfaceBuilder.addDnsServer(InetAddresses.parse(dns))
                    } catch (e: Exception) {
                        Log.w(TAG, "Failed parsing DNS server", e)
                    }
                }

                wgInterfaceBuilder.setMtu(wgConfig.mtu)

                if (wgConfig.allowedApplications.isNotEmpty()) {
                    for (app in wgConfig.allowedApplications) {
                        wgInterfaceBuilder.includeApplication(app)
                    }
                } else if (wgConfig.blockedApplications.isNotEmpty()) {
                    for (app in wgConfig.blockedApplications) {
                        wgInterfaceBuilder.excludeApplication(app)
                    }
                }

                val wgPeerBuilder = Peer.Builder()
                wgPeerBuilder.parsePublicKey(wgConfig.serverPublicKey)
                wgPeerBuilder.parseEndpoint(wgConfig.serverEndpoint)
                wgPeerBuilder.setPersistentKeepalive(wgConfig.persistentKeepalive)

                for (allowedIp in wgConfig.allowedIps) {
                    try {
                        wgPeerBuilder.addAllowedIp(InetNetwork.parse(allowedIp))
                    } catch (e: Exception) {
                        Log.w(TAG, "Failed parsing allowed IP", e)
                    }
                }

                val builtConfig = Config.Builder()
                    .setInterface(wgInterfaceBuilder.build())
                    .addPeer(wgPeerBuilder.build())
                    .build()

                val tunnelInstance = WireGuardTunnelInstance(wgConfig.peerId)
                activeTunnel = tunnelInstance
                backend?.setState(tunnelInstance, Tunnel.State.UP, builtConfig)
                startStatisticsPolling(tunnelInstance)
                waitForHandshake(tunnelInstance, wgConfig)
            } catch (e: Exception) {
                Log.e(TAG, "Failed to bring up WireGuard tunnel", e)
                running.set(false)
                listener.onEngineFailed(
                    NativeTunnelErrorCode.TUNNEL_START_FAILED,
                    e.message ?: "WireGuard tunnel start failed"
                )
            }
        }
    }

    private fun waitForHandshake(tunnel: WireGuardTunnelInstance, config: TunnelConfig) {
        stopHandshakeWait()
        handshakeExecutor = Executors.newSingleThreadScheduledExecutor()
        val deadline = System.currentTimeMillis() + HANDSHAKE_TIMEOUT_MS
        handshakeExecutor?.scheduleAtFixedRate({
            try {
                if (!running.get()) {
                    stopHandshakeWait()
                    return@scheduleAtFixedRate
                }
                val stats = backend?.getStatistics(tunnel)
                val peerKey = stats?.peers()?.firstOrNull()
                val handshakeMs = if (peerKey != null) {
                    stats?.peer(peerKey)?.latestHandshakeEpochMillis() ?: 0L
                } else 0L

                if (handshakeMs > 0L) {
                    connectedSinceEpochMs = System.currentTimeMillis()
                    listener?.onEngineReady(SessionConfig.WireGuard(config))
                    stopHandshakeWait()
                    return@scheduleAtFixedRate
                }

                if (System.currentTimeMillis() >= deadline) {
                    Log.w(TAG, "WireGuard handshake timeout")
                    running.set(false)
                    listener?.onEngineFailed(
                        NativeTunnelErrorCode.HANDSHAKE_TIMEOUT,
                        "Secure tunnel handshake timed out"
                    )
                    stop()
                }
            } catch (e: Exception) {
                Log.w(TAG, "Handshake polling error", e)
            }
        }, 0, 1, TimeUnit.SECONDS)
    }

    private fun startStatisticsPolling(tunnel: WireGuardTunnelInstance) {
        stopStatisticsPolling()
        statsExecutor = Executors.newSingleThreadScheduledExecutor()
        statsExecutor?.scheduleAtFixedRate({
            try {
                if (!running.get()) return@scheduleAtFixedRate
                val stats = backend?.getStatistics(tunnel)
                val rx = stats?.totalRx() ?: 0L
                val tx = stats?.totalTx() ?: 0L
                val latestHandshake = try {
                    val peerKey = stats?.peers()?.firstOrNull()
                    if (peerKey != null) {
                        stats.peer(peerKey)?.latestHandshakeEpochMillis() ?: 0L
                    } else 0L
                } catch (_: Throwable) {
                    0L
                }
                listener?.onStatistics(
                    TunnelStatistics(
                        rxBytes = rx,
                        txBytes = tx,
                        latestHandshakeEpochMs = latestHandshake,
                        connectedSinceEpochMs = connectedSinceEpochMs
                    )
                )
            } catch (_: Exception) {
            }
        }, 1, 2, TimeUnit.SECONDS)
    }

    private fun stopHandshakeWait() {
        handshakeExecutor?.shutdownNow()
        handshakeExecutor = null
    }

    private fun stopStatisticsPolling() {
        statsExecutor?.shutdownNow()
        statsExecutor = null
    }

    override fun stop() {
        running.set(false)
        stopStatisticsPolling()
        stopHandshakeWait()
        Executors.newSingleThreadExecutor().execute {
            try {
                activeTunnel?.let { tunnel ->
                    backend?.setState(tunnel, Tunnel.State.DOWN, null)
                }
            } catch (e: Exception) {
                Log.e(TAG, "Error shutting down WireGuard backend", e)
            } finally {
                activeTunnel = null
                activeConfig = null
                connectedSinceEpochMs = 0L
                backend = null
            }
        }
    }

    override fun isRunning(): Boolean = running.get()

    override fun getStatistics(): TunnelStatistics {
        val tunnel = activeTunnel ?: return TunnelStatistics()
        return try {
            val stats = backend?.getStatistics(tunnel)
            val rx = stats?.totalRx() ?: 0L
            val tx = stats?.totalTx() ?: 0L
            val latestHandshake = try {
                val peerKey = stats?.peers()?.firstOrNull()
                if (peerKey != null) {
                    stats.peer(peerKey)?.latestHandshakeEpochMillis() ?: 0L
                } else 0L
            } catch (_: Throwable) {
                0L
            }
            TunnelStatistics(rx, tx, latestHandshake, connectedSinceEpochMs)
        } catch (_: Exception) {
            TunnelStatistics(connectedSinceEpochMs = connectedSinceEpochMs)
        }
    }

    private class WireGuardTunnelInstance(private val tunnelName: String) : Tunnel {
        override fun getName(): String = tunnelName
        override fun onStateChange(state: Tunnel.State) {
            Log.d(TAG, "WireGuard tunnel state: $state")
        }
    }
}
