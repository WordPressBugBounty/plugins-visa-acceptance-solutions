<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Component\Core;

interface JWT
{
    /**
     * Returns the payload of the JWT. null is a valid payload (e.g. JWS with detached payload).
     */
    public function getPayload(): ?string;
}