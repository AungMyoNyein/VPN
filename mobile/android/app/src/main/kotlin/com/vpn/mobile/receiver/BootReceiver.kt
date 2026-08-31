package com.vpn.mobile.receiver

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.util.Log
import com.vpn.mobile.tunnel.VpnTunnelService

class BootReceiver : BroadcastReceiver() {
    companion object {
        private const val TAG = "BootReceiver"
        private const val PREFS_NAME = "vpn_app_preferences"
        private const val KEY_AUTO_CONNECT = "auto_connect_enabled"
    }

    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action == Intent.ACTION_BOOT_COMPLETED || intent.action == "android.intent.action.QUICKBOOT_POWERON") {
            val prefs = context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            val autoConnect = prefs.getBoolean(KEY_AUTO_CONNECT, false)
            Log.i(TAG, "Device booted. Auto-connect preference: $autoConnect")
            // If auto-connect is enabled, the UI or service initialization will negotiate connection when opened
        }
    }
}
