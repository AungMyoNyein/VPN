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
import android.util.Log
import androidx.core.app.NotificationCompat
import com.vpn.mobile.MainActivity
import com.vpn.mobile.tunnel.engine.EngineListener
import com.vpn.mobile.tunnel.engine.VlessEngine
import com.vpn.mobile.tunnel.engine.VpnEngine
import com.vpn.mobile.tunnel.engine.WireGuardEngine
import java.util.concurrent.atomic.AtomicReference

class VpnTunnelService : VpnService(), EngineListener {

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
    private var activeEngine: VpnEngine? = null
    private var activeConfig: SessionConfig? = null
    private var connectivityManager: ConnectivityManager? = null
    private var networkCallback: ConnectivityManager.NetworkCallback? = null

    override fun onCreate() {
        super.onCreate()
        createNotificationChannel()
        connectivityManager = getSystemService(Context.CONNECTIVITY_SERVICE) as? ConnectivityManager
    }

    override fun onBind(intent: Intent?): IBinder {
        val vpnBinder = super.onBind(intent)
        return vpnBinder ?: binder
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            ACTION_CONNECT -> {
                val configMap = intent.getSerializableExtra(EXTRA_CONFIG) as? HashMap<*, *>
                if (configMap != null) {
                    @Suppress("UNCHECKED_CAST")
                    startTunnel(SessionConfig.fromMap(configMap as Map<String, Any?>))
                }
            }
            ACTION_DISCONNECT -> stopTunnel()
        }
        return START_NOT_STICKY
    }

    fun startTunnel(config: SessionConfig) {
        try {
            when (config) {
                is SessionConfig.WireGuard -> config.config.validate()
                is SessionConfig.Vless -> config.config.validate()
            }
        } catch (e: Exception) {
            val code = if (config is SessionConfig.Vless) {
                NativeTunnelErrorCode.VLESS_CONFIG_INVALID
            } else {
                NativeTunnelErrorCode.INVALID_CONFIG
            }
            notifyState(NativeTunnelState.ERROR, code, e.message)
            return
        }

        activeConfig = config
        notifyState(NativeTunnelState.CONNECTING)
        val label = config.locationLabel.ifBlank { config.protocolLabel }
        startForeground(NOTIFICATION_ID, buildNotification("Connecting…", label, config.protocolLabel, false))

        activeEngine?.stop()
        activeEngine = when (config) {
            is SessionConfig.WireGuard -> WireGuardEngine()
            is SessionConfig.Vless -> VlessEngine()
        }
        activeEngine?.start(this, config, this)
    }

    override fun onEngineReady(config: SessionConfig) {
        notifyState(NativeTunnelState.CONNECTED)
        val label = config.locationLabel.ifBlank { config.protocolLabel }
        updateNotification(buildNotification("VPN Protected", label, config.protocolLabel, true))
        registerNetworkCallback()
    }

    override fun onEngineFailed(code: String, message: String) {
        notifyState(NativeTunnelState.ERROR, code, message)
        stopTunnel()
    }

    override fun onStatistics(stats: TunnelStatistics) {
        notifyStatistics(stats)
    }

    fun stopTunnel() {
        notifyState(NativeTunnelState.DISCONNECTING)
        unregisterNetworkCallback()
        activeEngine?.stop()
        activeEngine = null
        activeConfig = null
        notifyState(NativeTunnelState.DISCONNECTED)
        stopForeground(STOP_FOREGROUND_REMOVE)
        stopSelf()
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
                    activeConfig?.let { cfg ->
                        val label = cfg.locationLabel.ifBlank { cfg.protocolLabel }
                        updateNotification(
                            buildNotification("Reconnecting…", label, cfg.protocolLabel, false)
                        )
                    }
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

    private fun createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel(
                NOTIFICATION_CHANNEL_ID,
                "VPN Service",
                NotificationManager.IMPORTANCE_LOW
            ).apply {
                description = "ZenTunnel VPN connection status"
                setShowBadge(false)
            }
            val notificationManager = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
            notificationManager.createNotificationChannel(channel)
        }
    }

    private fun buildNotification(
        statusText: String,
        locationLabel: String,
        protocolLabel: String,
        protected: Boolean
    ): Notification {
        val subtitle = listOf(locationLabel, protocolLabel)
            .filter { it.isNotBlank() }
            .joinToString(" • ")

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

        val title = if (protected) "ZenTunnel — VPN Protected" else "ZenTunnel"

        return NotificationCompat.Builder(this, NOTIFICATION_CHANNEL_ID)
            .setContentTitle(title)
            .setContentText(if (subtitle.isNotBlank()) "$statusText\n$subtitle" else statusText)
            .setStyle(
                NotificationCompat.BigTextStyle()
                    .bigText(if (subtitle.isNotBlank()) "$statusText\n$subtitle" else statusText)
            )
            .setSmallIcon(android.R.drawable.ic_lock_lock)
            .setOngoing(true)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .setContentIntent(pendingOpenApp)
            .addAction(android.R.drawable.ic_menu_close_clear_cancel, "Disconnect", pendingDisconnect)
            .build()
    }

    private fun updateNotification(notification: Notification) {
        val notificationManager = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        notificationManager.notify(NOTIFICATION_ID, notification)
    }

    override fun onDestroy() {
        super.onDestroy()
        unregisterNetworkCallback()
        activeEngine?.stop()
        activeEngine = null
    }
}
