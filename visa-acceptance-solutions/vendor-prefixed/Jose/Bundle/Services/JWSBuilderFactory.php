<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Services;

use Pymt_Vas\Dependencies\Jose\Component\Core\AlgorithmManagerFactory;
use Pymt_Vas\Dependencies\Psr\EventDispatcher\EventDispatcherInterface;
final class JWSBuilderFactory
{
    public function __construct(private readonly AlgorithmManagerFactory $signatureAlgorithmManagerFactory, private readonly EventDispatcherInterface $eventDispatcher)
    {
    }
    /**
     * This method creates a JWSBuilder using the given algorithm aliases.
     *
     * @param string[] $algorithms
     */
    public function create(array $algorithms): JWSBuilder
    {
        $algorithmManager = $this->signatureAlgorithmManagerFactory->create($algorithms);
        return new JWSBuilder($algorithmManager, $this->eventDispatcher);
    }
}