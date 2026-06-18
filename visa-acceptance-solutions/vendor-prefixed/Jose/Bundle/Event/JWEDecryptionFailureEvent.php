<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Event;

use Pymt_Vas\Dependencies\Jose\Component\Core\JWKSet;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\JWE;
use Pymt_Vas\Dependencies\Symfony\Contracts\EventDispatcher\Event;
final class JWEDecryptionFailureEvent extends Event
{
    public function __construct(private readonly JWE $jwe, private readonly JWKSet $JWKSet)
    {
    }
    public function getJWKSet(): JWKSet
    {
        return $this->JWKSet;
    }
    public function getJwe(): JWE
    {
        return $this->jwe;
    }
}