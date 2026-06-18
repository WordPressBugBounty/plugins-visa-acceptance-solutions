<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Component\Signature\Serializer;

use Pymt_Vas\Dependencies\Jose\Component\Signature\JWS;
interface JWSSerializer
{
    /**
     * The name of the serialization.
     */
    public function name(): string;
    public function displayName(): string;
    /**
     * Converts a JWS into a string.
     */
    public function serialize(JWS $jws, ?int $signatureIndex = null): string;
    /**
     * Loads data and return a JWS object.
     *
     * @param string $input A string that represents a JWS
     */
    public function unserialize(string $input): JWS;
}