<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Event;

use Pymt_Vas\Dependencies\Jose\Component\Signature\JWS;
use Pymt_Vas\Dependencies\Symfony\Contracts\EventDispatcher\Event;
final class JWSBuiltSuccessEvent extends Event
{
    public function __construct(private readonly JWS $jws)
    {
    }
    public function getJws(): JWS
    {
        return $this->jws;
    }
}