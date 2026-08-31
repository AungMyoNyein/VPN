package com.vpn.mobile.security

import android.content.Context
import android.content.SharedPreferences
import android.os.Build
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/**
 * Interface for hardware-backed or secure keystore storage of WireGuard private keys.
 */
interface SecureKeyStorage {
    fun savePrivateKey(alias: String, plainBase64Key: String)
    fun getPrivateKey(alias: String): String?
    fun deletePrivateKey(alias: String)
}

/**
 * Android Keystore AES-GCM 256-bit encrypted secure key storage.
 * The private key is never stored in plaintext on disk or SharedPreferences.
 */
class AndroidKeystoreSecureStorage(private val context: Context) : SecureKeyStorage {

    private val keyStoreAlias = "VPN_WG_KEY_WRAPPER"
    private val prefName = "vpn_secure_wg_vault"
    private val transformation = "AES/GCM/NoPadding"
    private val androidKeyStore = "AndroidKeyStore"
    private val ivSeparator = "]]IV_SEPARATOR[["

    private val prefs: SharedPreferences by lazy {
        context.getSharedPreferences(prefName, Context.MODE_PRIVATE)
    }

    private fun getOrCreateMasterKey(): SecretKey {
        val keyStore = KeyStore.getInstance(androidKeyStore).apply { load(null) }
        if (keyStore.containsAlias(keyStoreAlias)) {
            val entry = keyStore.getEntry(keyStoreAlias, null) as? KeyStore.SecretKeyEntry
            if (entry != null) {
                return entry.secretKey
            }
        }

        val keyGenerator = KeyGenerator.getInstance(
            KeyProperties.KEY_ALGORITHM_AES,
            androidKeyStore
        )
        val keyGenSpec = KeyGenParameterSpec.Builder(
            keyStoreAlias,
            KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT
        )
            .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
            .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
            .setKeySize(256)
            .build()

        keyGenerator.init(keyGenSpec)
        return keyGenerator.generateKey()
    }

    override fun savePrivateKey(alias: String, plainBase64Key: String) {
        try {
            val masterKey = getOrCreateMasterKey()
            val cipher = Cipher.getInstance(transformation)
            cipher.init(Cipher.ENCRYPT_MODE, masterKey)
            val iv = cipher.iv
            val encryptedBytes = cipher.doFinal(plainBase64Key.toByteArray(Charsets.UTF_8))

            val ivString = Base64.encodeToString(iv, Base64.NO_WRAP)
            val cipherString = Base64.encodeToString(encryptedBytes, Base64.NO_WRAP)
            val storedValue = "$ivString$ivSeparator$cipherString"

            prefs.edit().putString(alias, storedValue).apply()
        } catch (e: Exception) {
            throw SecurityException("Failed to securely store WireGuard private key: ${e.message}", e)
        }
    }

    override fun getPrivateKey(alias: String): String? {
        val stored = prefs.getString(alias, null) ?: return null
        val parts = stored.split(ivSeparator)
        if (parts.size != 2) {
            return null
        }

        return try {
            val iv = Base64.decode(parts[0], Base64.NO_WRAP)
            val encryptedBytes = Base64.decode(parts[1], Base64.NO_WRAP)

            val masterKey = getOrCreateMasterKey()
            val cipher = Cipher.getInstance(transformation)
            val spec = GCMParameterSpec(128, iv)
            cipher.init(Cipher.DECRYPT_MODE, masterKey, spec)

            val plainBytes = cipher.doFinal(encryptedBytes)
            String(plainBytes, Charsets.UTF_8)
        } catch (e: Exception) {
            null
        }
    }

    override fun deletePrivateKey(alias: String) {
        prefs.edit().remove(alias).apply()
    }
}
