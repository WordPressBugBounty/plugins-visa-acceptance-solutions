<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Component\Encryption\Algorithm\KeyEncryption;

use Pymt_Vas\Dependencies\AESKW\A128KW as Wrapper;
final class ECDHSSA128KW extends ECDHSSAESKW
{
    public function name(): string
    {
        return 'ECDH-SS+A128KW';
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