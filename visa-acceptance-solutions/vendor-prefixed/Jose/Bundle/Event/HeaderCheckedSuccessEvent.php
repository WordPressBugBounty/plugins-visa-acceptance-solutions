<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Event;

use Pymt_Vas\Dependencies\Jose\Component\Core\JWT;
use Pymt_Vas\Dependencies\Symfony\Contracts\EventDispatcher\Event;
final class HeaderCheckedSuccessEvent extends Event
{
    public function __construct(private readonly JWT $jwt, private readonly int $index, private readonly array $mandatoryHeaderParameters)
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
}