<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Event;

use Pymt_Vas\Dependencies\Jose\Component\Core\JWKSet;
use Pymt_Vas\Dependencies\Jose\Component\Signature\JWS;
use Pymt_Vas\Dependencies\Symfony\Contracts\EventDispatcher\Event;
final class JWSVerificationFailureEvent extends Event
{
    public function __construct(private readonly JWS $jws, private readonly JWKSet $JWKSet, private readonly ?string $detachedPayload)
    {
    }
    public function getJws(): JWS
    {
        return $this->jws;
    }
    public function getJWKSet(): JWKSet
    {
        return $this->JWKSet;
    }
    public function getDetachedPayload(): ?string
    {
        return $this->detachedPayload;
    }
}