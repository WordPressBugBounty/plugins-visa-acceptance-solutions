<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Component\KeyManagement\Analyzer;

use Pymt_Vas\Dependencies\Jose\Component\Core\JWK;
final class AlgorithmAnalyzer implements KeyAnalyzer
{
    public function analyze(JWK $jwk, MessageBag $bag): void
    {
        if (!$jwk->has('alg')) {
            $bag->add(Message::medium('The parameter "alg" should be added.'));
        }
    }
}