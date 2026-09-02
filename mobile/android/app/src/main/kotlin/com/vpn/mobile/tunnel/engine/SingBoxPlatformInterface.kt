package com.vpn.mobile.tunnel.engine

import android.content.Context
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.net.VpnService
import android.os.Build
import android.os.ParcelFileDescriptor
import android.system.OsConstants
import android.util.Log
import io.nekohasekai.libbox.InterfaceUpdateListener
import io.nekohasekai.libbox.Libbox
import io.nekohasekai.libbox.NetworkInterface
import io.nekohasekai.libbox.NetworkInterfaceIterator
import io.nekohasekai.libbox.Notification
import io.nekohasekai.libbox.PlatformInterface
import io.nekohasekai.libbox.StringIterator
import io.nekohasekai.libbox.TunOptions
import io.nekohasekai.libbox.WIFIState
import java.io.File
import java.net.Inet6Address
import java.net.InterfaceAddress
import java.net.NetworkInterface as JavaNetworkInterface

class SingBoxPlatformInterface(
    private val vpnService: VpnService,
    private val appContext: Context,
    private val dnsServers: List<String> = emptyList(),
) : PlatformInterface {

    companion object {
        private const val TAG = "SingBoxPlatform"
    }

    private val connectivity =
        appContext.getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager

  var tunPfd: ParcelFileDescriptor? = null
        private set

    /** Android-assigned TUN interface name (e.g. tun1), for traffic readiness checks. */
    var tunInterfaceName: String? = null
        private set

    private var interfaceListener: InterfaceUpdateListener? = null
    private var networkCallback: ConnectivityManager.NetworkCallback? = null

    override fun usePlatformAutoDetectInterfaceControl(): Boolean = true

    override fun autoDetectInterfaceControl(fd: Int) {
        if (!vpnService.protect(fd)) {
            throw Exception("failed to protect outbound socket fd=$fd")
        }
    }

    override fun openTun(options: TunOptions): Int {
        if (VpnService.prepare(vpnService) != null) {
            throw Exception("android: missing vpn permission")
        }

        val builder = vpnService.Builder()
            .setSession("ZenTunnel")
            .setMtu(options.mtu)

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            builder.setMetered(false)
        }

        val inet4Address = options.inet4Address
        while (inet4Address.hasNext()) {
            val address = inet4Address.next()
            builder.addAddress(address.address(), address.prefix())
        }

        // IPv4-only: do not add IPv6 addresses or ::/0 routes.
        if (options.autoRoute) {
            var dnsAdded = false
            for (dns in dnsServers) {
                if (dns.isNotBlank()) {
                    builder.addDnsServer(dns)
                    dnsAdded = true
                }
            }
            if (!dnsAdded) {
                try {
                    val dnsBox = options.dnsServerAddress
                    val dns = dnsBox.value
                    if (!dns.isNullOrBlank()) {
                        builder.addDnsServer(dns)
                    }
                } catch (_: Exception) {
                    builder.addDnsServer("1.1.1.1")
                }
            }
            builder.addRoute("0.0.0.0", 0)
        }

        tunPfd?.close()
        val beforeTun = currentTunInterfaces()
        val pfd = builder.establish() ?: throw Exception("android: vpn establish failed")
        tunPfd = pfd
        tunInterfaceName = detectTunInterfaceName(beforeTun)
        return pfd.fd
    }

    private fun currentTunInterfaces(): Set<String> {
        return try {
            File("/sys/class/net").listFiles()
                ?.mapNotNull { entry ->
                    val name = entry.name
                    if (name.startsWith("tun")) name else null
                }
                ?.toSet()
                ?: emptySet()
        } catch (_: Exception) {
            emptySet()
        }
    }

    private fun detectTunInterfaceName(before: Set<String>): String {
        val after = currentTunInterfaces()
        val created = after - before
        if (created.size == 1) {
            return created.first()
        }
        if (after.isNotEmpty()) {
            return after.sorted().last()
        }
        return "tun0"
    }

    override fun writeLog(message: String?) {
        if (message.isNullOrBlank()) return
        // Redact potential UUID patterns in logs.
        val sanitized = message.replace(
            Regex("[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}"),
            "[REDACTED-UUID]"
        )
        Log.i(TAG, sanitized)
    }

    override fun useProcFS(): Boolean = Build.VERSION.SDK_INT < Build.VERSION_CODES.Q

    override fun findConnectionOwner(
        ipProtocol: Int,
        sourceAddress: String?,
        sourcePort: Int,
        destinationAddress: String?,
        destinationPort: Int
    ): Int {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.Q) {
            throw Exception("connection owner requires API 29+")
        }
        val uid = connectivity.getConnectionOwnerUid(
            ipProtocol,
            java.net.InetSocketAddress(sourceAddress, sourcePort),
            java.net.InetSocketAddress(destinationAddress, destinationPort)
        )
        if (uid == android.os.Process.INVALID_UID) {
            throw Exception("android: connection owner not found")
        }
        return uid
    }

    override fun packageNameByUid(uid: Int): String {
        val packages = vpnService.packageManager.getPackagesForUid(uid)
        return packages?.firstOrNull() ?: ""
    }

    override fun uidByPackageName(packageName: String?): Int {
        return try {
            if (packageName.isNullOrBlank()) -1
            else vpnService.packageManager.getApplicationInfo(packageName, 0).uid
        } catch (_: Exception) {
            -1
        }
    }

    override fun startDefaultInterfaceMonitor(listener: InterfaceUpdateListener?) {
        interfaceListener = listener
        if (networkCallback != null) return

        val callback = object : ConnectivityManager.NetworkCallback() {
            override fun onAvailable(network: Network) = publishDefaultNetwork(network)
            override fun onCapabilitiesChanged(network: Network, caps: NetworkCapabilities) =
                publishDefaultNetwork(network)

            override fun onLost(network: Network) {
                listener?.updateDefaultInterface("", -1, false, false)
            }
        }
        networkCallback = callback
        try {
            connectivity.registerDefaultNetworkCallback(callback)
            connectivity.activeNetwork?.let { publishDefaultNetwork(it) }
        } catch (e: Exception) {
            Log.e(TAG, "Failed to register default network callback", e)
        }
    }

    private fun publishDefaultNetwork(network: Network) {
        val listener = interfaceListener ?: return
        val caps = connectivity.getNetworkCapabilities(network)
        val link = connectivity.getLinkProperties(network)
        val ifName = link?.interfaceName ?: ""
        val ifIndex = ifName.takeIf { it.isNotBlank() }?.let { name ->
            try {
                JavaNetworkInterface.getByName(name)?.index ?: -1
            } catch (_: Exception) {
                -1
            }
        } ?: -1
        val expensive = caps?.hasCapability(NetworkCapabilities.NET_CAPABILITY_NOT_METERED) != true
        val constrained = caps?.hasCapability(NetworkCapabilities.NET_CAPABILITY_NOT_RESTRICTED) != true
        listener.updateDefaultInterface(ifName, ifIndex, expensive, constrained)
    }

    override fun closeDefaultInterfaceMonitor(listener: InterfaceUpdateListener?) {
        networkCallback?.let {
            try {
                connectivity.unregisterNetworkCallback(it)
            } catch (e: Exception) {
                Log.w(TAG, "Failed to unregister network callback", e)
            }
        }
        networkCallback = null
        interfaceListener = null
    }

    override fun getInterfaces(): NetworkInterfaceIterator {
        val networks = connectivity.allNetworks
        val javaInterfaces = JavaNetworkInterface.getNetworkInterfaces()?.toList().orEmpty()
        val result = mutableListOf<NetworkInterface>()

        for (network in networks) {
            val link = connectivity.getLinkProperties(network) ?: continue
            val caps = connectivity.getNetworkCapabilities(network) ?: continue
            val ifName = link.interfaceName ?: continue
            val javaIf = javaInterfaces.find { it.name == ifName } ?: continue

            val boxIf = NetworkInterface()
            boxIf.name = ifName
            boxIf.index = javaIf.index
            boxIf.mtu = runCatching { javaIf.mtu }.getOrDefault(1500)
            boxIf.dnsServer = StringArray(link.dnsServers.mapNotNull { it.hostAddress }.iterator())
            boxIf.type = when {
                caps.hasTransport(NetworkCapabilities.TRANSPORT_WIFI) -> Libbox.InterfaceTypeWIFI
                caps.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR) -> Libbox.InterfaceTypeCellular
                caps.hasTransport(NetworkCapabilities.TRANSPORT_ETHERNET) -> Libbox.InterfaceTypeEthernet
                else -> Libbox.InterfaceTypeOther
            }
            boxIf.addresses = StringArray(
                javaIf.interfaceAddresses.map { it.toPrefix() }.iterator()
            )
            var flags = OsConstants.IFF_UP or OsConstants.IFF_RUNNING
            if (javaIf.isLoopback) flags = flags or OsConstants.IFF_LOOPBACK
            boxIf.flags = flags
            boxIf.metered = !caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_NOT_METERED)
            result.add(boxIf)
        }
        return InterfaceArray(result.iterator())
    }

    override fun underNetworkExtension(): Boolean = false

    override fun includeAllNetworks(): Boolean = false

    override fun readWIFIState(): WIFIState? = null

    override fun clearDNSCache() {}

    override fun sendNotification(notification: Notification?) {}

    fun closeTun() {
        tunPfd?.close()
        tunPfd = null
        tunInterfaceName = null
    }

    private fun InterfaceAddress.toPrefix(): String {
        return if (address is Inet6Address) {
            "${Inet6Address.getByAddress(address.address).hostAddress}/${networkPrefixLength}"
        } else {
            "${address.hostAddress}/${networkPrefixLength}"
        }
    }

    private class StringArray(private val iterator: Iterator<String>) : StringIterator {
        override fun len(): Int = 0
        override fun hasNext(): Boolean = iterator.hasNext()
        override fun next(): String = iterator.next()
    }

    private class InterfaceArray(private val iterator: Iterator<NetworkInterface>) :
        NetworkInterfaceIterator {
        override fun hasNext(): Boolean = iterator.hasNext()
        override fun next(): NetworkInterface = iterator.next()
    }
}
