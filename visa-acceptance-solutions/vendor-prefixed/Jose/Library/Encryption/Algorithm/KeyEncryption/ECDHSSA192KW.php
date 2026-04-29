<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Component\Encryption\Algorithm\KeyEncryption;

use Pymt_Vas\Dependencies\AESKW\A192KW as Wrapper;
final class ECDHSSA192KW extends ECDHSSAESKW
{
    public function name(): string
    {
        return 'ECDH-SS+A192KW';
    }
    protected function getWrapper(): Wrapper
    {
        return new Wrapper();
    }
    protected function getKeyLength(): int
    {
        return 192;
    }
}