<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Event;

use Pymt_Vas\Dependencies\Jose\Component\Core\JWKSet;
use Pymt_Vas\Dependencies\Jose\Component\Signature\JWS;
use Pymt_Vas\Dependencies\Symfony\Contracts\EventDispatcher\Event;
final class NestedTokenLoadingSuccessEvent extends Event
{
    public function __construct(private readonly string $token, private readonly JWS $jws, private readonly JWKSet $signatureKeySet, private readonly JWKSet $encryptionKeySet, private readonly int $signature)
    {
    }
    public function getJws(): JWS
    {
        return $this->jws;
    }
    public function getToken(): string
    {
        return $this->token;
    }
    public function getSignatureKeySet(): JWKSet
    {
        return $this->signatureKeySet;
    }
    public function getEncryptionKeySet(): JWKSet
    {
        return $this->encryptionKeySet;
    }
    public function getSignature(): int
    {
        return $this->signature;
    }
}