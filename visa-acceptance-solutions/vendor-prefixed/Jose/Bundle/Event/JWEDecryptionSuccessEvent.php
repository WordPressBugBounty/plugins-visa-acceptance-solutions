<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Event;

use Pymt_Vas\Dependencies\Jose\Component\Core\JWK;
use Pymt_Vas\Dependencies\Jose\Component\Core\JWKSet;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\JWE;
use Pymt_Vas\Dependencies\Symfony\Contracts\EventDispatcher\Event;
final class JWEDecryptionSuccessEvent extends Event
{
    public function __construct(private readonly JWE $jwe, private readonly JWKSet $JWKSet, private readonly JWK $JWK, private readonly int $recipient)
    {
    }
    public function getJws(): JWE
    {
        return $this->jwe;
    }
    public function getJWKSet(): JWKSet
    {
        return $this->JWKSet;
    }
    public function getJWK(): JWK
    {
        return $this->JWK;
    }
    public function getRecipient(): int
    {
        return $this->recipient;
    }
}