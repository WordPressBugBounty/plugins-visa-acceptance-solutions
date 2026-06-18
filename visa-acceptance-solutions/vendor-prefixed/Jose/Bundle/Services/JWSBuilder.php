<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Services;

use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Event\JWSBuiltFailureEvent;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Event\JWSBuiltSuccessEvent;
use Pymt_Vas\Dependencies\Jose\Component\Core\AlgorithmManager;
use Pymt_Vas\Dependencies\Jose\Component\Signature\JWS;
use Pymt_Vas\Dependencies\Jose\Component\Signature\JWSBuilder as BaseJWSBuilder;
use Pymt_Vas\Dependencies\Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;
final class JWSBuilder extends BaseJWSBuilder
{
    public function __construct(AlgorithmManager $signatureAlgorithmManager, private readonly EventDispatcherInterface $eventDispatcher)
    {
        parent::__construct($signatureAlgorithmManager);
    }
    public function build(): JWS
    {
        try {
            $jws = parent::build();
            $this->eventDispatcher->dispatch(new JWSBuiltSuccessEvent($jws));
            return $jws;
        } catch (Throwable $throwable) {
            $this->eventDispatcher->dispatch(new JWSBuiltFailureEvent($this->payload, $this->signatures, $this->isPayloadDetached, $this->isPayloadEncoded, $throwable));
            throw $throwable;
        }
    }
}