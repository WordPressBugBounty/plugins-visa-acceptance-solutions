<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\SpomkyLabs\Pki\ASN1\Type;

use Pymt_Vas\Dependencies\SpomkyLabs\Pki\ASN1\Feature\ElementBase;
use Pymt_Vas\Dependencies\SpomkyLabs\Pki\ASN1\Feature\Stringable;
/**
 * Interface to mark types that correspond to ASN.1 specification's character strings. That being all simple strings and
 * time types.
 */
interface StringType extends ElementBase, Stringable
{
}