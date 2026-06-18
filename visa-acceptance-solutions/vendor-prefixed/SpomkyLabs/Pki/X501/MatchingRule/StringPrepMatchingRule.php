<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\SpomkyLabs\Pki\X501\MatchingRule;

use Pymt_Vas\Dependencies\SpomkyLabs\Pki\X501\StringPrep\StringPreparer;
/**
 * Base class for matching rules employing string preparement semantics.
 */
abstract class StringPrepMatchingRule extends MatchingRule
{
    protected function __construct(private readonly StringPreparer $preparer)
    {
    }
    public function compare(string $assertion, string $value): ?bool
    {
        $assertion = $this->preparer->prepare($assertion);
        $value = $this->preparer->prepare($value);
        return strcmp($assertion, $value) === 0;
    }
}