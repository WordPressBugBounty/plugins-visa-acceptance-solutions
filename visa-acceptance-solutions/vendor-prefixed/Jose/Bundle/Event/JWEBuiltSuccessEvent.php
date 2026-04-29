<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Event;

use Pymt_Vas\Dependencies\Jose\Component\Encryption\JWE;
use Pymt_Vas\Dependencies\Symfony\Contracts\EventDispatcher\Event;
final class JWEBuiltSuccessEvent extends Event
{
    public function __construct(private readonly JWE $jwe)
    {
    }
    public function getJwe(): JWE
    {
        return $this->jwe;
    }
}