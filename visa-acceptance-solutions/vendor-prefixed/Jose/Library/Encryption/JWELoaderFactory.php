<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Component\Encryption;

use Pymt_Vas\Dependencies\Jose\Component\Checker\HeaderCheckerManagerFactory;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\Serializer\JWESerializerManagerFactory;
class JWELoaderFactory
{
    public function __construct(private readonly JWESerializerManagerFactory $jweSerializerManagerFactory, private readonly JWEDecrypterFactory $jweDecrypterFactory, private readonly ?HeaderCheckerManagerFactory $headerCheckerManagerFactory)
    {
    }
    /**
     * Creates a JWELoader using the given serializer aliases, encryption algorithm aliases, compression method aliases
     * and header checker aliases.
     */
    public function create(array $serializers, array $encryptionAlgorithms, null|array $contentEncryptionAlgorithms = null, null|array $compressionMethods = null, array $headerCheckers = []): JWELoader
    {
        if ($contentEncryptionAlgorithms !== null) {
            $encryptionAlgorithms = array_merge($encryptionAlgorithms, $contentEncryptionAlgorithms);
        }
        $serializerManager = $this->jweSerializerManagerFactory->create($serializers);
        $jweDecrypter = $this->jweDecrypterFactory->create($encryptionAlgorithms, null, $compressionMethods);
        if ($this->headerCheckerManagerFactory !== null) {
            $headerCheckerManager = $this->headerCheckerManagerFactory->create($headerCheckers);
        } else {
            $headerCheckerManager = null;
        }
        return new JWELoader($serializerManager, $jweDecrypter, $headerCheckerManager);
    }
}