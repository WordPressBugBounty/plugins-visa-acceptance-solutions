<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Component\Encryption\Algorithm\KeyEncryption;

use InvalidArgumentException;
use Pymt_Vas\Dependencies\Jose\Component\Core\JWK;
use Pymt_Vas\Dependencies\Jose\Component\Core\Util\Base64UrlSafe;
use function in_array;
use function is_string;
final class Dir implements DirectEncryption
{
    public function getCEK(JWK $key): string
    {
        if (!in_array($key->get('kty'), $this->allowedKeyTypes(), true)) {
            throw new InvalidArgumentException('Wrong key type.');
        }
        if (!$key->has('k')) {
            throw new InvalidArgumentException('The key parameter "k" is missing.');
        }
        $k = $key->get('k');
        if (!is_string($k)) {
            throw new InvalidArgumentException('The key parameter "k" is invalid.');
        }
        return Base64UrlSafe::decodeNoPadding($k);
    }
    public function name(): string
    {
        return 'dir';
    }
    public function allowedKeyTypes(): array
    {
        return ['oct'];
    }
    public function getKeyManagementMode(): string
    {
        return self::MODE_DIRECT;
    }
}