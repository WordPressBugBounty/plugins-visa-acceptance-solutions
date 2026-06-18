<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\SpomkyLabs\Pki\ASN1\Type\Primitive;

use Pymt_Vas\Dependencies\SpomkyLabs\Pki\ASN1\Type\PrimitiveString;
use Pymt_Vas\Dependencies\SpomkyLabs\Pki\ASN1\Type\UniversalClass;
/**
 * Implements *CHARACTER STRING* type.
 */
final class CharacterString extends PrimitiveString
{
    use UniversalClass;
    private function __construct(string $string)
    {
        parent::__construct(self::TYPE_CHARACTER_STRING, $string);
    }
    public static function create(string $string): self
    {
        return new self($string);
    }
}