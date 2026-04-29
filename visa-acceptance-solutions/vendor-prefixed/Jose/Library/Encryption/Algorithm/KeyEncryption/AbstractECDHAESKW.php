<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Component\Encryption\Algorithm\KeyEncryption;

use Pymt_Vas\Dependencies\AESKW\Wrapper as WrapperInterface;
use RuntimeException;
abstract class AbstractECDHAESKW implements KeyAgreementWithKeyWrapping
{
    public function __construct()
    {
        if (!interface_exists(WrapperInterface::class)) {
            throw new RuntimeException('Please install "spomky-labs/aes-key-wrap" to use AES-KW algorithms');
        }
    }
    public function allowedKeyTypes(): array
    {
        return ['EC', 'OKP'];
    }
    public function getKeyManagementMode(): string
    {
        return self::MODE_WRAP;
    }
    abstract protected function getWrapper(): WrapperInterface;
    abstract protected function getKeyLength(): int;
}