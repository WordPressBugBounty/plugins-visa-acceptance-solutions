<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Component\Encryption\Algorithm\KeyEncryption;

use Pymt_Vas\Dependencies\AESKW\A128KW as Wrapper;
final class PBES2HS256A128KW extends PBES2AESKW
{
    public function name(): string
    {
        return 'PBES2-HS256+A128KW';
    }
    protected function getWrapper(): Wrapper
    {
        return new Wrapper();
    }
    protected function getHashAlgorithm(): string
    {
        return 'sha256';
    }
    protected function getKeySize(): int
    {
        return 16;
    }
}