<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Event;

use Pymt_Vas\Dependencies\Symfony\Contracts\EventDispatcher\Event;
final class NestedTokenIssuedEvent extends Event
{
    public function __construct(private readonly string $nestedToken)
    {
    }
    public function getNestedToken(): string
    {
        return $this->nestedToken;
    }
}