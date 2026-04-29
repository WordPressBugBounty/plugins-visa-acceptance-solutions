<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Event;

use Pymt_Vas\Dependencies\Jose\Component\Core\JWKSet;
use Pymt_Vas\Dependencies\Symfony\Contracts\EventDispatcher\Event;
use Throwable;
final class NestedTokenLoadingFailureEvent extends Event
{
    public function __construct(private readonly string $token, private readonly JWKSet $signatureKeySet, private readonly JWKSet $encryptionKeySet, private readonly Throwable $throwable)
    {
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
    public function getThrowable(): Throwable
    {
        return $this->throwable;
    }
}