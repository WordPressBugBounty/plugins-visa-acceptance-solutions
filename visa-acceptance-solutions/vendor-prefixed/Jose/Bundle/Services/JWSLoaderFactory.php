<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Services;

use Pymt_Vas\Dependencies\Jose\Component\Signature\Serializer\JWSSerializerManagerFactory;
use Pymt_Vas\Dependencies\Psr\EventDispatcher\EventDispatcherInterface;
final class JWSLoaderFactory
{
    public function __construct(private readonly JWSSerializerManagerFactory $jwsSerializerManagerFactory, private readonly JWSVerifierFactory $jwsVerifierFactory, private readonly ?HeaderCheckerManagerFactory $headerCheckerManagerFactory, private readonly EventDispatcherInterface $eventDispatcher)
    {
    }
    /**
     * Creates a JWSLoader using the given serializer aliases, signature algorithm aliases and (optionally) the header
     * checker aliases.
     */
    public function create(array $serializers, array $algorithms, array $headerCheckers = []): JWSLoader
    {
        $serializerManager = $this->jwsSerializerManagerFactory->create($serializers);
        $jwsVerifier = $this->jwsVerifierFactory->create($algorithms);
        if ($this->headerCheckerManagerFactory !== null) {
            $headerCheckerManager = $this->headerCheckerManagerFactory->create($headerCheckers);
        } else {
            $headerCheckerManager = null;
        }
        return new JWSLoader($serializerManager, $jwsVerifier, $headerCheckerManager, $this->eventDispatcher);
    }
}