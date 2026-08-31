package com.vpn.mobile.tunnel

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.net.NetworkRequest
import android.net.VpnService
import android.os.Binder
import android.os.Build
import android.os.Handler
import android.os.IBinder
import android.os.Looper
import android.os.ParcelFileDescriptor
import android.util.Log
import androidx.core.app.NotificationCompat
import com.vpn.mobile.MainActivity
import com.wireguard.android.backend.GoBackend
import com.wireguard.android.backend.Tunnel
import com.wireguard.config.Config
import com.wireguard.config.InetAddresses
import com.wireguard.config.InetEndpoint
import com.wireguard.config.InetNetwork
import com.wireguard.config.Interface
import com.wireguard.config.Peer
import com.wireguard.crypto.Key
import com.wireguard.crypto.KeyPair
import java.io.ByteArrayInputStream
import java.net.InetAddress
import java.util.concurrent.Executors
import java.util.concurrent.ScheduledExecutorService
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicReference

class VpnTunnelService : VpnService() {

    companion object {
        const val TAG = "VpnTunnelService"
        const val NOTIFICATION_CHANNEL_ID = "vpn_tunnel_status"
        const val NOTIFICATION_ID = 1001

        const val ACTION_CONNECT = "com.vpn.mobile.action.CONNECT"
        const val ACTION_DISCONNECT = "com.vpn.mobile.action.DISCONNECT"
        const val EXTRA_CONFIG = "com.vpn.mobile.extra.CONFIG"

        private val currentStateRef = AtomicReference(NativeTunnelState.DISCONNECTED)
        private val currentStatsRef = AtomicReference(TunnelStatistics())
        private val listeners = mutableSetOf<TunnelStateListener>()

        fun getCurrentState(): NativeTunnelState = currentStateRef.get()
        fun getCurrentStatistics(): TunnelStatistics = currentStatsRef.get()

        fun addListener(listener: TunnelStateListener) {
            synchronized(listeners) {
                listeners.add(listener)
            }
            listener.onStateChanged(currentStateRef.get())
            listener.onStatisticsChanged(currentStatsRef.get())
        }

        fun removeListener(listener: TunnelStateListener) {
            synchronized(listeners) {
                listeners.remove(listener)
            }
        }

        internal fun notifyState(state: NativeTunnelState, errorCode: String? = null, errorMessage: String? = null) {
            currentStateRef.set(state)
            val listCopy = synchronized(listeners) { listeners.toList() }
            Handler(Looper.getMainLooper()).post {
                for (listener in listCopy) {
                    listener.onStateChanged(state)
                    if (errorCode != null) {
                        listener.onError(errorCode, errorMessage ?: "")
                    }
                }
            }
        }

        internal fun notifyStatistics(stats: TunnelStatistics) {
            currentStatsRef.set(stats)
            val listCopy = synchronized(listeners) { listeners.toList() }
            Handler(Looper.getMainLooper()).post {
                for (listener in listCopy) {
                    listener.onStatisticsChanged(stats)
                }
            }
        }
    }

    interface TunnelStateListener {
        fun onStateChanged(state: NativeTunnelState)
        fun onStatisticsChanged(stats: TunnelStatistics)
        fun onError(code: String, message: String)
    }

    inner class LocalBinder : Binder() {
        fun getService(): VpnTunnelService = this@VpnTunnelService
    }

    private val binder = LocalBinder()
    private var backend: GoBackend? = null
    private var activeTunnel: WireGuardTunnelInstance? = null
    private var activeConfig: TunnelConfig? = null
    private var connectivityManager: ConnectivityManager? = null
    private var networkCallback: ConnectivityManager.NetworkCallback? = null
    private var statsExecutor: ScheduledExecutorService? = null
    private var connectedSinceEpochMs: Long = 0L

    override fun onCreate() {
        super.onCreate()
        createNotificationChannel()
        backend = GoBackend(applicationContext)
        connectivityManager = getSystemService(Context.CONNECTIVITY_SERVICE) as? ConnectivityManager
    }

    override fun onBind(intent: Intent?): IBinder {
        val vpnBinder = super.onBind(intent)
        return vpnBinder ?: binder
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        val action = intent?.action
        when (action) {
            ACTION_CONNECT -> {
                val configMap = intent.getSerializableExtra(EXTRA_CONFIG) as? HashMap<*, *>
                if (configMap != null) {
                    @Suppress("UNCHECKED_CAST")
                    val parsedConfig = TunnelConfig.fromMap(configMap as Map<String, Any?>)
                    startTunnel(parsedConfig)
                }
            }
            ACTION_DISCONNECT -> {
                stopTunnel()
            }
        }
        return START_NOT_STICKY
    }

    fun startTunnel(config: TunnelConfig) {
        try {
            config.validate()
        } catch (e: Exception) {
            notifyState(NativeTunnelState.ERROR, NativeTunnelErrorCode.INVALID_CONFIG, e.message)
            return
        }

        activeConfig = config
        notifyState(NativeTunnelState.CONNECTING)
        startForeground(NOTIFICATION_ID, buildNotification("Connecting to ${config.serverEndpoint}…"))

        Executors.newSingleThreadExecutor().execute {
            try {
                // Build WireGuard library Config
                val wgInterfaceBuilder = Interface.Builder()
                val privateKeyObj = Key.fromBase64(config.privateKey)
                wgInterfaceBuilder.parsePrivateKey(config.privateKey)

                // IPv4 Address
                val clientAddressNet = InetNetwork.parse(config.clientAddress)
                wgInterfaceBuilder.addAddress(clientAddressNet)

                // DNS
                for (dns in config.dnsServers) {
                    try {
                        wgInterfaceBuilder.addDnsServer(InetAddresses.parse(dns))
                    } catch (e: Exception) {
                        Log.w(TAG, "Failed parsing DNS server: $dns", e)
                    }
                }

                // MTU
                wgInterfaceBuilder.setMtu(config.mtu)

                // Allowed/Blocked Applications if configured
                if (config.allowedApplications.isNotEmpty()) {
                    for (app in config.allowedApplications) {
                        wgInterfaceBuilder.includeApplication(app)
                    }
                } else if (config.blockedApplications.isNotEmpty()) {
                    for (app in config.blockedApplications) {
                        wgInterfaceBuilder.excludeApplication(app)
                    }
                }

                val wgPeerBuilder = Peer.Builder()
                wgPeerBuilder.parsePublicKey(config.serverPublicKey)
                wgPeerBuilder.parseEndpoint(config.serverEndpoint)
                wgPeerBuilder.setPersistentKeepalive(config.persistentKeepalive)

                // Allowed IPs (Strict IPv4 full tunnel 0.0.0.0/0)
                for (allowedIp in config.allowedIps) {
                    try {
                        wgPeerBuilder.addAllowedIp(InetNetwork.parse(allowedIp))
                    } catch (e: Exception) {
                        Log.w(TAG, "Failed parsing allowed IP: $allowedIp", e)
                    }
                }

                val wgConfig = Config.Builder()
                    .setInterface(wgInterfaceBuilder.build())
                    .addPeer(wgPeerBuilder.build())
                    .build()

                val tunnelInstance = WireGuardTunnelInstance(config.peerId)
                activeTunnel = tunnelInstance

                backend?.setState(tunnelInstance, Tunnel.State.UP, wgConfig)

                connectedSinceEpochMs = System.currentTimeMillis()
                notifyState(NativeTunnelState.CONNECTED)
                updateNotification("Connected to ${config.serverEndpoint}")
                registerNetworkCallback()
                startStatisticsPolling(tunnelInstance)

            } catch (e: Exception) {
                Log.e(TAG, "Failed to bring up WireGuard tunnel", e)
                notifyState(NativeTunnelState.ERROR, NativeTunnelErrorCode.TUNNEL_START_FAILED, e.message)
                stopForeground(STOP_FOREGROUND_REMOVE)
            }
        }
    }

    fun stopTunnel() {
        notifyState(NativeTunnelState.DISCONNECTING)
        unregisterNetworkCallback()
        stopStatisticsPolling()

        Executors.newSingleThreadExecutor().execute {
            try {
                activeTunnel?.let { tunnel ->
                    backend?.setState(tunnel, Tunnel.State.DOWN, null)
                }
            } catch (e: Exception) {
                Log.e(TAG, "Error shutting down backend", e)
            } finally {
                activeTunnel = null
                activeConfig = null
                connectedSinceEpochMs = 0L
                notifyState(NativeTunnelState.DISCONNECTED)
                stopForeground(STOP_FOREGROUND_REMOVE)
                stopSelf()
            }
        }
    }

    private fun registerNetworkCallback() {
        if (networkCallback != null) return
        val request = NetworkRequest.Builder()
            .addCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
            .build()

        val callback = object : ConnectivityManager.NetworkCallback() {
            override fun onAvailable(network: Network) {
                if (currentStateRef.get() == NativeTunnelState.RECONNECTING) {
                    activeConfig?.let { cfg ->
                        Log.i(TAG, "Underlying network restored, reconnecting tunnel…")
                        startTunnel(cfg)
                    }
                }
            }

            override fun onLost(network: Network) {
                if (currentStateRef.get() == NativeTunnelState.CONNECTED) {
                    Log.i(TAG, "Underlying network lost, entering RECONNECTING…")
                    notifyState(NativeTunnelState.RECONNECTING)
                    updateNotification("Reconnecting…")
                }
            }
        }

        networkCallback = callback
        try {
            connectivityManager?.registerNetworkCallback(request, callback)
        } catch (e: Exception) {
            Log.e(TAG, "Failed to register network callback", e)
        }
    }

    private fun unregisterNetworkCallback() {
        networkCallback?.let {
            try {
                connectivityManager?.unregisterNetworkCallback(it)
            } catch (e: Exception) {
                Log.w(TAG, "Failed to unregister network callback", e)
            }
            networkCallback = null
        }
    }

    private fun startStatisticsPolling(tunnel: WireGuardTunnelInstance) {
        stopStatisticsPolling()
        statsExecutor = Executors.newSingleThreadScheduledExecutor()
        statsExecutor?.scheduleAtFixedRate({
            try {
                val stats = backend?.getStatistics(tunnel)
                val rx = stats?.totalRx() ?: 0L
                val tx = stats?.totalTx() ?: 0L
                val latestHandshake = try {
                    val peerKey = stats?.peers()?.firstOrNull()
                    if (peerKey != null) {
                        stats.peer(peerKey)?.latestHandshakeEpochMillis() ?: 0L
                    } else 0L
                } catch (e: Throwable) {
                    0L
                }

                val statsModel = TunnelStatistics(
                    rxBytes = rx,
                    txBytes = tx,
                    latestHandshakeEpochMs = latestHandshake,
                    connectedSinceEpochMs = connectedSinceEpochMs
                )
                notifyStatistics(statsModel)
            } catch (e: Exception) {
                // Ignore transient stat polling errors
            }
        }, 1, 2, TimeUnit.SECONDS)
    }

    private fun stopStatisticsPolling() {
        statsExecutor?.shutdownNow()
        statsExecutor = null
    }

    private fun createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val name = "VPN Service"
            val descriptionText = "Shows active WireGuard VPN tunnel status"
            val importance = NotificationManager.IMPORTANCE_LOW
            val channel = NotificationChannel(NOTIFICATION_CHANNEL_ID, name, importance).apply {
                description = descriptionText
                setShowBadge(false)
            }
            val notificationManager = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
            notificationManager.createNotificationChannel(channel)
        }
    }

    private fun buildNotification(statusText: String): Notification {
        val openAppIntent = Intent(this, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP
        }
        val pendingOpenApp = PendingIntent.getActivity(
            this,
            0,
            openAppIntent,
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
        )

        val disconnectIntent = Intent(this, VpnTunnelService::class.java).apply {
            action = ACTION_DISCONNECT
        }
        val pendingDisconnect = PendingIntent.getService(
            this,
            1,
            disconnectIntent,
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
        )

        return NotificationCompat.Builder(this, NOTIFICATION_CHANNEL_ID)
            .setContentTitle("VPN Secure Tunnel")
            .setContentText(statusText)
            .setSmallIcon(android.R.drawable.ic_lock_lock)
            .setOngoing(true)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .setContentIntent(pendingOpenApp)
            .addAction(android.R.drawable.ic_menu_close_clear_cancel, "Disconnect", pendingDisconnect)
            .build()
    }

    private fun updateNotification(statusText: String) {
        val notificationManager = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        notificationManager.notify(NOTIFICATION_ID, buildNotification(statusText))
    }

    override fun onDestroy() {
        super.onDestroy()
        unregisterNetworkCallback()
        stopStatisticsPolling()
    }

    private class WireGuardTunnelInstance(private val tunnelName: String) : Tunnel {
        override fun getName(): String = tunnelName
        override fun onStateChange(state: Tunnel.State) {
            Log.d(TAG, "WireGuard tunnel $tunnelName state changed to: $state")
        }
    }
}
