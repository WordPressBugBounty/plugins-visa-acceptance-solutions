<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Component\Encryption\Algorithm\KeyEncryption;

use Pymt_Vas\Dependencies\Jose\Component\Core\JWK;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\Algorithm\KeyEncryptionAlgorithm;
interface DirectEncryption extends KeyEncryptionAlgorithm
{
    /**
     * Returns the CEK.
     *
     * @param JWK $key The key used to get the CEK
     */
    public function getCEK(JWK $key): string;
}