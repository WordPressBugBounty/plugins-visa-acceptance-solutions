<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Experimental\Signature;

use Pymt_Vas\Dependencies\Jose\Component\Signature\Algorithm\ECDSA;
final class ES256K extends ECDSA
{
    public function name(): string
    {
        return 'ES256K';
    }
    protected function getHashAlgorithm(): string
    {
        return 'sha256';
    }
    protected function getSignaturePartLength(): int
    {
        return 64;
    }
}