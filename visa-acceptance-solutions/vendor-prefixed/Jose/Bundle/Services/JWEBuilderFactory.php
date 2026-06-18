<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Services;

use Pymt_Vas\Dependencies\Jose\Component\Core\AlgorithmManagerFactory;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\Compression\CompressionMethodManagerFactory;
use Pymt_Vas\Dependencies\Psr\EventDispatcher\EventDispatcherInterface;
final class JWEBuilderFactory
{
    public function __construct(private readonly AlgorithmManagerFactory $algorithmManagerFactory, private readonly null|CompressionMethodManagerFactory $compressionMethodManagerFactory, private readonly EventDispatcherInterface $eventDispatcher)
    {
        if ($compressionMethodManagerFactory !== null) {
            pymt_vas_trigger_deprecation('web-token/jwt-library', '3.3.0', 'The parameter "$compressionMethodManagerFactory" is deprecated and will be removed in 4.0.0. Compression is not recommended for JWE. Please set "null" instead.');
        }
    }
    /**
     * This method creates a JWEBuilder using the given algorithm aliases.
     *
     * @param string[] $encryptionAlgorithms
     * @param string[] $contentEncryptionAlgorithms
     * @param null|string[] $compressionMethods
     */
    public function create(array $encryptionAlgorithms, null|array $contentEncryptionAlgorithms = null, null|array $compressionMethods = null): JWEBuilder
    {
        if ($contentEncryptionAlgorithms !== null) {
            pymt_vas_trigger_deprecation('web-token/jwt-library', '3.3.0', 'The parameter "$contentEncryptionAlgorithms" is deprecated and will be removed in 4.0.0. Please set "null" instead.');
            $encryptionAlgorithms = array_merge($encryptionAlgorithms, $contentEncryptionAlgorithms);
        }
        $algorithmManager = $this->algorithmManagerFactory->create($encryptionAlgorithms);
        if ($compressionMethods !== null) {
            pymt_vas_trigger_deprecation('web-token/jwt-library', '3.3.0', 'The parameter "$compressionMethods" is deprecated and will be removed in 4.0.0. Compression is not recommended for JWE. Please set "null" instead.');
        }
        $compressionMethodManager = $compressionMethods === null ? null : $this->compressionMethodManagerFactory?->create($compressionMethods);
        return new JWEBuilder($algorithmManager, null, $compressionMethodManager, $this->eventDispatcher);
    }
}