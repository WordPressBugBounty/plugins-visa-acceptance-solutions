<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Component\Signature\Algorithm;

final class PS384 extends RSAPSS
{
    public function name(): string
    {
        return 'PS384';
    }
    protected function getAlgorithm(): string
    {
        return 'sha384';
    }
}