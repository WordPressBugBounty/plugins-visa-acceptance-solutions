<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Experimental\KeyEncryption;

final class A256CTR extends AESCTR
{
    public function name(): string
    {
        return 'A256CTR';
    }
    protected function getMode(): string
    {
        return 'aes-256-ctr';
    }
}