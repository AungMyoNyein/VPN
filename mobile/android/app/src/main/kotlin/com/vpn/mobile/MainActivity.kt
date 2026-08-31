package com.vpn.mobile

import android.app.Activity
import android.content.Context
import android.content.Intent
import android.net.VpnService
import androidx.annotation.NonNull
import com.vpn.mobile.security.AndroidKeystoreSecureStorage
import com.vpn.mobile.security.SecureKeyStorage
import com.vpn.mobile.tunnel.NativeTunnelErrorCode
import com.vpn.mobile.tunnel.NativeTunnelState
import com.vpn.mobile.tunnel.TunnelConfig
import com.vpn.mobile.tunnel.TunnelStatistics
import com.vpn.mobile.tunnel.VpnTunnelService
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.EventChannel
import io.flutter.plugin.common.MethodCall
import io.flutter.plugin.common.MethodChannel

class MainActivity : FlutterActivity(), VpnTunnelService.TunnelStateListener {

    companion object {
        private const val METHOD_CHANNEL = "com.vpn.mobile/vpn_control"
        private const val EVENT_CHANNEL_STATE = "com.vpn.mobile/vpn_state_stream"
        private const val EVENT_CHANNEL_STATS = "com.vpn.mobile/vpn_stats_stream"
        private const val VPN_PREPARE_REQUEST_CODE = 2001
    }

    private var pendingMethodResult: MethodChannel.Result? = null
    private var stateEventSink: EventChannel.EventSink? = null
    private var statsEventSink: EventChannel.EventSink? = null
    private val keyStorage: SecureKeyStorage by lazy {
        AndroidKeystoreSecureStorage(applicationContext)
    }

    override fun configureFlutterEngine(@NonNull flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)

        // Method Channel
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, METHOD_CHANNEL)
            .setMethodCallHandler { call, result ->
                handleMethodCall(call, result)
            }

        // State Event Channel
        EventChannel(flutterEngine.dartExecutor.binaryMessenger, EVENT_CHANNEL_STATE)
            .setStreamHandler(object : EventChannel.StreamHandler {
                override fun onListen(arguments: Any?, events: EventChannel.EventSink?) {
                    stateEventSink = events
                    events?.success(VpnTunnelService.getCurrentState().rawValue)
                }

                override fun onCancel(arguments: Any?) {
                    stateEventSink = null
                }
            })

        // Stats Event Channel
        EventChannel(flutterEngine.dartExecutor.binaryMessenger, EVENT_CHANNEL_STATS)
            .setStreamHandler(object : EventChannel.StreamHandler {
                override fun onListen(arguments: Any?, events: EventChannel.EventSink?) {
                    statsEventSink = events
                    events?.success(VpnTunnelService.getCurrentStatistics().toMap())
                }

                override fun onCancel(arguments: Any?) {
                    statsEventSink = null
                }
            })

        VpnTunnelService.addListener(this)
    }

    private fun handleMethodCall(call: MethodCall, result: MethodChannel.Result) {
        when (call.method) {
            "prepareVpn" -> {
                val intent = VpnService.prepare(this)
                if (intent != null) {
                    pendingMethodResult = result
                    startActivityForResult(intent, VPN_PREPARE_REQUEST_CODE)
                } else {
                    // Already prepared/authorized
                    result.success(true)
                }
            }
            "connect" -> {
                val map = call.arguments as? Map<String, Any?>
                if (map == null) {
                    result.error(NativeTunnelErrorCode.INVALID_CONFIG, "Missing configuration arguments", null)
                    return
                }

                try {
                    val config = TunnelConfig.fromMap(map)
                    config.validate()

                    val intent = Intent(this, VpnTunnelService::class.java).apply {
                        action = VpnTunnelService.ACTION_CONNECT
                        putExtra(VpnTunnelService.EXTRA_CONFIG, HashMap(map))
                    }
                    startService(intent)
                    result.success(true)
                } catch (e: Exception) {
                    result.error(NativeTunnelErrorCode.INVALID_CONFIG, e.message, null)
                }
            }
            "disconnect" -> {
                val intent = Intent(this, VpnTunnelService::class.java).apply {
                    action = VpnTunnelService.ACTION_DISCONNECT
                }
                startService(intent)
                result.success(true)
            }
            "getState" -> {
                result.success(VpnTunnelService.getCurrentState().rawValue)
            }
            "getStatistics" -> {
                result.success(VpnTunnelService.getCurrentStatistics().toMap())
            }
            "savePrivateKey" -> {
                val alias = call.argument<String>("alias") ?: "client_wg_key"
                val privateKey = call.argument<String>("privateKey") ?: ""
                if (privateKey.isBlank()) {
                    result.error(NativeTunnelErrorCode.INVALID_CONFIG, "Private key cannot be blank", null)
                    return
                }
                try {
                    keyStorage.savePrivateKey(alias, privateKey)
                    result.success(true)
                } catch (e: Exception) {
                    result.error(NativeTunnelErrorCode.KEYSTORE_ERROR, e.message, null)
                }
            }
            "getPrivateKey" -> {
                val alias = call.argument<String>("alias") ?: "client_wg_key"
                try {
                    val key = keyStorage.getPrivateKey(alias)
                    result.success(key)
                } catch (e: Exception) {
                    result.error(NativeTunnelErrorCode.KEYSTORE_ERROR, e.message, null)
                }
            }
            "deletePrivateKey" -> {
                val alias = call.argument<String>("alias") ?: "client_wg_key"
                try {
                    keyStorage.deletePrivateKey(alias)
                    result.success(true)
                } catch (e: Exception) {
                    result.error(NativeTunnelErrorCode.KEYSTORE_ERROR, e.message, null)
                }
            }
            else -> result.notImplemented()
        }
    }

    override fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {
        super.onActivityResult(requestCode, resultCode, data)
        if (requestCode == VPN_PREPARE_REQUEST_CODE) {
            val granted = (resultCode == Activity.RESULT_OK)
            pendingMethodResult?.let {
                if (granted) {
                    it.success(true)
                } else {
                    it.error(NativeTunnelErrorCode.PERMISSION_DENIED, "VPN permission was denied by the user", null)
                }
            }
            pendingMethodResult = null
        }
    }

    override fun onStateChanged(state: NativeTunnelState) {
        runOnUiThread {
            stateEventSink?.success(state.rawValue)
        }
    }

    override fun onStatisticsChanged(stats: TunnelStatistics) {
        runOnUiThread {
            statsEventSink?.success(stats.toMap())
        }
    }

    override fun onError(code: String, message: String) {
        runOnUiThread {
            stateEventSink?.error(code, message, null)
        }
    }

    override fun onDestroy() {
        super.onDestroy()
        VpnTunnelService.removeListener(this)
    }
}
