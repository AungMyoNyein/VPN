package com.vpn.mobile

import com.vpn.mobile.tunnel.VlessTunnelConfig
import com.vpn.mobile.tunnel.engine.SingBoxConfigBuilder
import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertThrows
import org.junit.Assert.assertTrue
import org.junit.Test

class VlessTunnelConfigTest {

  private fun sampleConfig() = VlessTunnelConfig(
    connectionId = "PEER-VLESS-001",
    serverHost = "zentunnel.net",
    serverPort = 8443,
    uuid = "12345678-1234-1234-1234-123456789abc",
    sni = "zentunnel.net",
    dnsServers = listOf("1.1.1.1"),
    mtu = 1400,
    locationLabel = "Singapore"
  )

  @Test
  fun validConfigPassesValidation() {
    sampleConfig().validate()
  }

  @Test
  fun invalidPortFailsValidation() {
    val config = sampleConfig().copy(serverPort = 0)
    assertThrows(IllegalArgumentException::class.java) { config.validate() }
  }

  @Test
  fun toStringRedactsUuid() {
    val text = sampleConfig().toString()
    assertFalse(text.contains("12345678-1234-1234-1234-123456789abc"))
    assertTrue(text.contains("[REDACTED]"))
  }

  @Test
  fun singBoxConfigIsIpv4OnlyAndTlsEnabled() {
    val json = JSONObject(SingBoxConfigBuilder.build(sampleConfig()))
    val inbound = json.getJSONArray("inbounds").getJSONObject(0)
    assertEquals("tun", inbound.getString("type"))
    assertFalse(inbound.has("inet6_address"))

    val outbound = json.getJSONArray("outbounds").getJSONObject(0)
    val tls = outbound.getJSONObject("tls")
    assertTrue(tls.getBoolean("enabled"))
    assertFalse(tls.getBoolean("insecure"))
    assertEquals("zentunnel.net", tls.getString("server_name"))
  }
}
