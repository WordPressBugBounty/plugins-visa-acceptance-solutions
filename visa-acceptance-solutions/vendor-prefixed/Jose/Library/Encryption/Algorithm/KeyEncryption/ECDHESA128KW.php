<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Component\Encryption\Algorithm\KeyEncryption;

use Pymt_Vas\Dependencies\AESKW\A128KW as Wrapper;
final class ECDHESA128KW extends ECDHESAESKW
{
    public function name(): string
    {
        return 'ECDH-ES+A128KW';
    }
    protected function getWrapper(): Wrapper
    {
        return new Wrapper();
    }
    protected function getKeyLength(): int
    {
        return 128;
    }
}