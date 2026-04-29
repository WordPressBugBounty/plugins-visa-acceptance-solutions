<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Experimental\KeyEncryption;

final class A128CTR extends AESCTR
{
    public function name(): string
    {
        return 'A128CTR';
    }
    protected function getMode(): string
    {
        return 'aes-128-ctr';
    }
}