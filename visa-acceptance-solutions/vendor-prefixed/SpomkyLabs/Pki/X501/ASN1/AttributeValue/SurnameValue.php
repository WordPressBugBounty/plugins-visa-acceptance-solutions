<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\SpomkyLabs\Pki\X501\ASN1\AttributeValue;

use Pymt_Vas\Dependencies\SpomkyLabs\Pki\X501\ASN1\AttributeType;
use Pymt_Vas\Dependencies\SpomkyLabs\Pki\X501\ASN1\AttributeValue\Feature\DirectoryString;
/**
 * 'surname' attribute value.
 *
 * @see https://www.itu.int/ITU-T/formal-language/itu-t/x/x520/2012/SelectedAttributeTypes.html#SelectedAttributeTypes.surname
 */
final class SurnameValue extends DirectoryString
{
    public static function create(string $value, int $string_tag = DirectoryString::UTF8): static
    {
        return new static(AttributeType::OID_SURNAME, $value, $string_tag);
    }
}