<?php

namespace Pymt_Vas\Dependencies\CyberSource\Authentication\Util\JWE;

use Pymt_Vas\Dependencies\CyberSource\Authentication\Core\MerchantConfiguration;
use Pymt_Vas\Dependencies\SimpleJWT\JWE;
use Pymt_Vas\Dependencies\SimpleJWT\Keys\RSAKey;
use Pymt_Vas\Dependencies\SimpleJWT\Keys\KeySet;
use Pymt_Vas\Dependencies\phpseclib3\Crypt\PublicKeyLoader;
use Pymt_Vas\Dependencies\CyberSource\Authentication\Util\Cache as Cache;
class JWEUtility
{
    private static $cache = null;
    /** Key-encryption algorithms accepted for MLE response decryption. */
    private static $allowedAlgs = ['RSA-OAEP', 'RSA-OAEP-256'];
    /**
     * @deprecated This method has been marked as Deprecated and will be removed in coming releases.
     */
    private static function loadKeyFromPEMFile($path)
    {
        trigger_error("This method has been marked as Deprecated and will be removed in coming releases.", E_USER_DEPRECATED);
        return file_get_contents($path);
    }
    /**
     * @deprecated This method has been marked as Deprecated and will be removed in coming releases. Use decryptJWEUsingPrivateKey(\$privateKey, \$encodedResponse) instead.
     */
    public static function decryptJWEUsingPEM(MerchantConfiguration $merchantConfig, string $jweBase64Data)
    {
        trigger_error("This method has been marked as Deprecated and will be removed in coming releases. Use decryptJWEUsingPrivateKey(\$privateKey, \$encodedResponse) instead.", E_USER_DEPRECATED);
        if (!isset(self::$cache)) {
            self::$cache = new Cache();
        }
        $filePath = $merchantConfig->getJwePEMFileDirectory();
        if (!file_exists($filePath)) {
            return null;
        }
        // The cache returns the key as a SimpleJWT RSAKey (JWK), not raw PEM.
        $privateKey = self::$cache->grabKeyFromPEM($filePath);
        return self::decryptCompactJwe($privateKey, $jweBase64Data);
    }
    public static function decryptJWEUsingPrivateKey(string $privateKey, string $encodedResponse)
    {
        return self::decryptCompactJwe(self::createRSAKeyFromPem($privateKey), $encodedResponse);
    }
    /**
     * Decrypts a JWE Compact Serialization using the supplied RSA private key.
     *
     * The key-encryption algorithm (RSA-OAEP or RSA-OAEP-256) is read from the
     * token's protected header. SimpleJWT selects the recipient key by matching
     * the JWE 'kid', so the key is tagged with the token's kid when one is
     * present. The tagging is done on a clone so a cached/shared key object is
     * not mutated across calls.
     *
     * @param RSAKey $key the RSA private key as a SimpleJWT JWK object
     * @param string $encodedResponse the JWE compact serialization
     * @return string|null the decrypted payload, or null if decryption fails
     */
    private static function decryptCompactJwe(RSAKey $key, string $encodedResponse)
    {
        $header = self::parseProtectedHeader($encodedResponse);
        $alg = isset($header['alg']) ? $header['alg'] : 'RSA-OAEP-256';
        if (!in_array($alg, self::$allowedAlgs, true)) {
            return null;
        }
        // Tag the key with the token's kid so SimpleJWT can select it during
        // decryption, cloning first so a cached/shared key is not mutated.
        if (isset($header['kid'])) {
            $key = clone $key;
            $key->setKeyId($header['kid']);
        }
        $keySet = new KeySet();
        $keySet->add($key);
        try {
            $jwe = JWE::decrypt($encodedResponse, $keySet, $alg);
            return $jwe->getPlaintext();
        } catch (\Exception $e) {
            return null;
        }
    }
    /**
     * Builds a SimpleJWT RSAKey (JWK) from an RSA private key PEM, normalising
     * PKCS#8 keys to the PKCS#1 form that SimpleJWT requires. Building the JWK
     * up front lets callers cache the key object instead of retaining the raw
     * PEM private-key material as a string.
     *
     * @param string $privateKeyPem the RSA private key in PEM format
     * @return RSAKey the key as a SimpleJWT JWK object
     */
    public static function createRSAKeyFromPem(string $privateKeyPem)
    {
        return new RSAKey(self::normaliseToPkcs1($privateKeyPem), 'pem');
    }
    /**
     * Decodes the protected (first) segment of a JWE compact serialization.
     *
     * @param string $compactJwe the JWE compact serialization
     * @return array the decoded protected header, or an empty array on failure
     */
    private static function parseProtectedHeader(string $compactJwe)
    {
        $parts = explode('.', $compactJwe);
        if (count($parts) < 1 || $parts[0] === '') {
            return [];
        }
        $decoded = base64_decode(strtr($parts[0], '-_', '+/'));
        if ($decoded === false) {
            return [];
        }
        $header = json_decode($decoded, true);
        return is_array($header) ? $header : [];
    }
    /**
     * Ensures an RSA private key PEM is in PKCS#1 format, which SimpleJWT requires.
     * PKCS#8 (`-----BEGIN PRIVATE KEY-----`) keys are converted using phpseclib.
     *
     * @param string $pem the RSA private key in PEM format
     * @return string the key in PKCS#1 PEM format
     */
    private static function normaliseToPkcs1(string $pem)
    {
        if (strpos($pem, 'BEGIN RSA PRIVATE KEY') !== false) {
            return $pem;
        }
        return PublicKeyLoader::load($pem)->toString('PKCS1');
    }
}