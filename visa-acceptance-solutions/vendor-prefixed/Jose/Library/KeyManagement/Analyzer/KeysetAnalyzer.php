<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Component\KeyManagement\Analyzer;

use Pymt_Vas\Dependencies\Jose\Component\Core\JWKSet;
interface KeysetAnalyzer
{
    /**
     * This method will analyse the key set and add messages to the message bag if needed.
     */
    public function analyze(JWKSet $JWKSet, MessageBag $bag): void;
}