<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Event;

use Pymt_Vas\Dependencies\Jose\Component\Core\JWT;
use Pymt_Vas\Dependencies\Symfony\Contracts\EventDispatcher\Event;
use Throwable;
final class HeaderCheckedFailureEvent extends Event
{
    public function __construct(private readonly JWT $jwt, private readonly int $index, private readonly array $mandatoryHeaderParameters, private readonly Throwable $throwable)
    {
    }
    public function getJwt(): JWT
    {
        return $this->jwt;
    }
    public function getIndex(): int
    {
        return $this->index;
    }
    public function getMandatoryHeaderParameters(): array
    {
        return $this->mandatoryHeaderParameters;
    }
    public function getThrowable(): Throwable
    {
        return $this->throwable;
    }
}