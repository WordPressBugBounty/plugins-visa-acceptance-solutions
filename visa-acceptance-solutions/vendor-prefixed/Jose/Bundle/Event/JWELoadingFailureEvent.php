<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Event;

use Pymt_Vas\Dependencies\Jose\Component\Core\JWKSet;
use Pymt_Vas\Dependencies\Symfony\Contracts\EventDispatcher\Event;
use Throwable;
final class JWELoadingFailureEvent extends Event
{
    public function __construct(private readonly string $token, private readonly JWKSet $JWKSet, private readonly Throwable $throwable)
    {
    }
    public function getJWKSet(): JWKSet
    {
        return $this->JWKSet;
    }
    public function getToken(): string
    {
        return $this->token;
    }
    public function getThrowable(): Throwable
    {
        return $this->throwable;
    }
}