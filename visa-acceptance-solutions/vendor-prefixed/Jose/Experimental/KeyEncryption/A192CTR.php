<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Experimental\KeyEncryption;

final class A192CTR extends AESCTR
{
    public function name(): string
    {
        return 'A192CTR';
    }
    protected function getMode(): string
    {
        return 'aes-192-ctr';
    }
}