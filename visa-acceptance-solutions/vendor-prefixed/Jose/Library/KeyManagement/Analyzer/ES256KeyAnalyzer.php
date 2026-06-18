<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Component\KeyManagement\Analyzer;

use Pymt_Vas\Dependencies\Jose\Component\Core\Util\Ecc\Curve;
use Pymt_Vas\Dependencies\Jose\Component\Core\Util\Ecc\NistCurve;
final class ES256KeyAnalyzer extends ESKeyAnalyzer
{
    protected function getAlgorithmName(): string
    {
        return 'ES256';
    }
    protected function getCurveName(): string
    {
        return 'P-256';
    }
    protected function getCurve(): Curve
    {
        return NistCurve::curve256();
    }
    protected function getKeySize(): int
    {
        return 256;
    }
}