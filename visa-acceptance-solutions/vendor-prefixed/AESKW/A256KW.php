<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\AESKW;

final class A256KW implements Wrapper
{
    use AESKW;
    protected static function getMethod(): string
    {
        return 'aes-256-ecb';
    }
}