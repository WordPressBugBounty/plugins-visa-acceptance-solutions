<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Component\Encryption\Algorithm\KeyEncryption;

use Pymt_Vas\Dependencies\AESKW\A256KW as Wrapper;
final class ECDHESA256KW extends ECDHESAESKW
{
    public function name(): string
    {
        return 'ECDH-ES+A256KW';
    }
    protected function getWrapper(): Wrapper
    {
        return new Wrapper();
    }
    protected function getKeyLength(): int
    {
        return 256;
    }
}