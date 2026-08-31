package com.vpn.mobile

import com.vpn.mobile.tunnel.NativeTunnelErrorCode
import com.vpn.mobile.tunnel.NativeTunnelState
import com.vpn.mobile.tunnel.TunnelConfig
import com.vpn.mobile.tunnel.TunnelStatistics
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertThrows
import org.junit.Assert.assertTrue
import org.junit.Test

class TunnelConfigTest {

    @Test
    fun testValidTunnelConfigPassesValidation() {
        val config = TunnelConfig(
            peerId = "PEER-001",
            privateKey = "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=",
            clientAddress = "10.200.20.2/32",
            serverPublicKey = "BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB=",
            serverEndpoint = "sg01.vpn.example.com:51820",
            dnsServers = listOf("1.1.1.1", "1.0.0.1"),
            allowedIps = listOf("0.0.0.0/0"),
            persistentKeepalive = 25,
            mtu = 1420
        )

        config.validate()
        assertEquals("PEER-001", config.peerId)
        assertFalse(config.toString().contains("AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="))
        assertTrue(config.toString().contains("[REDACTED]"))
    }

    @Test
    fun testInvalidEndpointFailsValidation() {
        val config = TunnelConfig(
            peerId = "PEER-001",
            privateKey = "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=",
            clientAddress = "10.200.20.2/32",
            serverPublicKey = "BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB=",
            serverEndpoint = "sg01.vpn.example.com", // missing port
            dnsServers = listOf("1.1.1.1")
        )

        assertThrows(IllegalArgumentException::class.java) {
            config.validate()
        }
    }

    @Test
    fun testInvalidMtuFailsValidation() {
        val config = TunnelConfig(
            peerId = "PEER-001",
            privateKey = "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=",
            clientAddress = "10.200.20.2/32",
            serverPublicKey = "BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB=",
            serverEndpoint = "sg01.vpn.example.com:51820",
            mtu = 400 // Too small
        )

        assertThrows(IllegalArgumentException::class.java) {
            config.validate()
        }
    }

    @Test
    fun testFromMapParsing() {
        val map = mapOf(
            "peerId" to "PEER-MAP-123",
            "privateKey" to "TEST_PRIV_KEY",
            "clientAddress" to "10.200.20.5/32",
            "serverPublicKey" to "TEST_PUB_KEY",
            "serverEndpoint" to "192.168.1.1:51820",
            "dnsServers" to listOf("8.8.8.8"),
            "allowedIps" to listOf("0.0.0.0/0"),
            "persistentKeepalive" to 20,
            "mtu" to 1380
        )

        val config = TunnelConfig.fromMap(map)
        assertEquals("PEER-MAP-123", config.peerId)
        assertEquals(1380, config.mtu)
        assertEquals(20, config.persistentKeepalive)
    }

    @Test
    fun testNativeTunnelStateMapping() {
        assertEquals(NativeTunnelState.CONNECTED, NativeTunnelState.fromRaw("connected"))
        assertEquals(NativeTunnelState.DISCONNECTED, NativeTunnelState.fromRaw("disconnected"))
        assertEquals(NativeTunnelState.RECONNECTING, NativeTunnelState.fromRaw("reconnecting"))
        assertEquals(NativeTunnelState.ERROR, NativeTunnelState.fromRaw("error"))
        assertEquals(NativeTunnelState.DISCONNECTED, NativeTunnelState.fromRaw("unknown_value"))
    }

    @Test
    fun testStatisticsModel() {
        val stats = TunnelStatistics(
            rxBytes = 1024L,
            txBytes = 2048L,
            latestHandshakeEpochMs = 1700000000000L,
            connectedSinceEpochMs = 1699999000000L
        )

        val map = stats.toMap()
        assertEquals(1024L, map["rxBytes"])
        assertEquals(2048L, map["txBytes"])
        assertEquals(1700000000000L, map["latestHandshakeEpochMs"])
        assertEquals(1699999000000L, map["connectedSinceEpochMs"])
    }
}
