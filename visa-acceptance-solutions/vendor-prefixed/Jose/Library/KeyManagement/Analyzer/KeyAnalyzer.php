<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Component\KeyManagement\Analyzer;

use Pymt_Vas\Dependencies\Jose\Component\Core\JWK;
interface KeyAnalyzer
{
    /**
     * This method will analyse the key and add messages to the message bag if needed.
     */
    public function analyze(JWK $jwk, MessageBag $bag): void;
}