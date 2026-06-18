<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Experimental\KeyEncryption;

use Pymt_Vas\Dependencies\Jose\Component\Encryption\Algorithm\KeyEncryption\RSA;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\Algorithm\KeyEncryption\Util\RSACrypt;
final class RSAOAEP512 extends RSA
{
    public function getEncryptionMode(): int
    {
        return RSACrypt::ENCRYPTION_OAEP;
    }
    public function getHashAlgorithm(): string
    {
        return 'sha512';
    }
    public function name(): string
    {
        return 'RSA-OAEP-512';
    }
}