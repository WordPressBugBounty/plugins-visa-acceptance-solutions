<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Component\KeyManagement\Analyzer;

use Pymt_Vas\Dependencies\Jose\Component\Core\Util\Ecc\Curve;
use Pymt_Vas\Dependencies\Jose\Component\Core\Util\Ecc\NistCurve;
final class ES384KeyAnalyzer extends ESKeyAnalyzer
{
    protected function getAlgorithmName(): string
    {
        return 'ES384';
    }
    protected function getCurveName(): string
    {
        return 'P-384';
    }
    protected function getCurve(): Curve
    {
        return NistCurve::curve384();
    }
    protected function getKeySize(): int
    {
        return 384;
    }
}