<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Controller;

use Pymt_Vas\Dependencies\Symfony\Component\HttpFoundation\Response;
class JWKSetController
{
    public function __construct(private readonly string $jwkset)
    {
    }
    public function __invoke(): Response
    {
        return new Response($this->jwkset, Response::HTTP_OK, ['Content-Type' => 'application/jwk-set+json; charset=UTF-8']);
    }
}