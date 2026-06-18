<?php

namespace Pymt_Vas\Dependencies\CyberSource\Authentication\Util\JWE;

use Pymt_Vas\Dependencies\CyberSource\Authentication\Core\MerchantConfiguration;
use Pymt_Vas\Dependencies\Jose\Component\Core\AlgorithmManager;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\Algorithm\ContentEncryption\A256GCM;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\Algorithm\KeyEncryption\RSAOAEP;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\Algorithm\KeyEncryption\RSAOAEP256;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\Compression\CompressionMethodManager;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\Compression\Deflate;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\JWEDecrypter;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\Serializer\CompactSerializer;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\Serializer\JWESerializerManager;
use Pymt_Vas\Dependencies\Jose\Component\KeyManagement\JWKFactory;
use Pymt_Vas\Dependencies\CyberSource\Authentication\Util\Cache as Cache;
class JWEUtility
{
    private static $cache = null;
    /**
     * @deprecated This method has been marked as Deprecated and will be removed in coming releases.
     */
    private static function loadKeyFromPEMFile($path)
    {
        trigger_error("This method has been marked as Deprecated and will be removed in coming releases.", E_USER_DEPRECATED);
        return JWKFactory::createFromKeyFile(
            $path,
            '',
            // Secret if the key is encrypted
            ['use' => 'enc']
        );
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
        $jweKey = self::$cache->grabKeyFromPEM($filePath);
        $serializerManager = new JWESerializerManager([new CompactSerializer()]);
        // The key encryption algorithm manager with RSA-OAEP and RSA-OAEP-256 algorithms.
        $keyEncryptionAlgorithmManager = new AlgorithmManager([new RSAOAEP(), new RSAOAEP256()]);
        // The content encryption algorithm manager with the A256GCM algorithm.
        $contentEncryptionAlgorithmManager = new AlgorithmManager([new A256GCM()]);
        // The compression method manager with the DEF (Deflate) method.
        $compressionMethodManager = new CompressionMethodManager([new Deflate()]);
        $jweDecrypter = new JWEDecrypter($keyEncryptionAlgorithmManager, $contentEncryptionAlgorithmManager, $compressionMethodManager);
        $jwe = $serializerManager->unserialize($jweBase64Data);
        if ($jweDecrypter->decryptUsingKey($jwe, $jweKey, 0)) {
            return $jwe->getPayload();
        } else {
            return null;
        }
    }
    public static function decryptJWEUsingPrivateKey(string $privateKey, string $encodedResponse)
    {
        $jwk = JWKFactory::createFromKey($privateKey);
        // The key encryption algorithm manager with RSA-OAEP and RSA-OAEP-256 algorithms.
        $keyEncryptionAlgorithmManager = new AlgorithmManager([new RSAOAEP(), new RSAOAEP256()]);
        // The content encryption algorithm manager with the A256CBC-HS256 algorithm.
        $contentEncryptionAlgorithmManager = new AlgorithmManager([new A256GCM()]);
        // The serializer manager. We only use the JWE Compact Serialization Mode.
        $serializerManager = new JWESerializerManager([new CompactSerializer()]);
        $jweDecrypter = new JWEDecrypter($keyEncryptionAlgorithmManager, $contentEncryptionAlgorithmManager, new CompressionMethodManager([new Deflate()]));
        $jwe = $serializerManager->unserialize($encodedResponse);
        if ($jweDecrypter->decryptUsingKey($jwe, $jwk, 0)) {
            return $jwe->getPayload();
        } else {
            return null;
        }
    }
}