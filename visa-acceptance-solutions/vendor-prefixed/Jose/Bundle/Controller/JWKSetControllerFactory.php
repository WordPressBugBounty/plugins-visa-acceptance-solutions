<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Controller;

use Pymt_Vas\Dependencies\Jose\Component\Core\JWKSet;
use Pymt_Vas\Dependencies\Jose\Component\Core\Util\JsonConverter;
class JWKSetControllerFactory
{
    public function create(JWKSet $jwkset): JWKSetController
    {
        return new JWKSetController(JsonConverter::encode($jwkset));
    }
}