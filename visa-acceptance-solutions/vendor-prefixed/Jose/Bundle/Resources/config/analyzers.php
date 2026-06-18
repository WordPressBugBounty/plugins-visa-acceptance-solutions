<?php

declare (strict_types=1);
use Pymt_Vas\Dependencies\Jose\Component\Core\Util\Ecc\NistCurve;
use Pymt_Vas\Dependencies\Jose\Component\KeyManagement\Analyzer\AlgorithmAnalyzer;
use Pymt_Vas\Dependencies\Jose\Component\KeyManagement\Analyzer\ES256KeyAnalyzer;
use Pymt_Vas\Dependencies\Jose\Component\KeyManagement\Analyzer\ES384KeyAnalyzer;
use Pymt_Vas\Dependencies\Jose\Component\KeyManagement\Analyzer\ES512KeyAnalyzer;
use Pymt_Vas\Dependencies\Jose\Component\KeyManagement\Analyzer\HS256KeyAnalyzer;
use Pymt_Vas\Dependencies\Jose\Component\KeyManagement\Analyzer\HS384KeyAnalyzer;
use Pymt_Vas\Dependencies\Jose\Component\KeyManagement\Analyzer\HS512KeyAnalyzer;
use Pymt_Vas\Dependencies\Jose\Component\KeyManagement\Analyzer\KeyAnalyzerManager;
use Pymt_Vas\Dependencies\Jose\Component\KeyManagement\Analyzer\KeyIdentifierAnalyzer;
use Pymt_Vas\Dependencies\Jose\Component\KeyManagement\Analyzer\KeysetAnalyzerManager;
use Pymt_Vas\Dependencies\Jose\Component\KeyManagement\Analyzer\MixedKeyTypes;
use Pymt_Vas\Dependencies\Jose\Component\KeyManagement\Analyzer\MixedPublicAndPrivateKeys;
use Pymt_Vas\Dependencies\Jose\Component\KeyManagement\Analyzer\NoneAnalyzer;
use Pymt_Vas\Dependencies\Jose\Component\KeyManagement\Analyzer\OctAnalyzer;
use Pymt_Vas\Dependencies\Jose\Component\KeyManagement\Analyzer\UsageAnalyzer;
use Pymt_Vas\Dependencies\Jose\Component\KeyManagement\Analyzer\ZxcvbnKeyAnalyzer;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use ZxcvbnPhp\Zxcvbn;
return function (ContainerConfigurator $container): void {
    $container = $container->services()->defaults()->private()->autoconfigure()->autowire();
    $container->set(KeyAnalyzerManager::class)->public();
    $container->set(KeysetAnalyzerManager::class)->public();
    $container->set(AlgorithmAnalyzer::class);
    $container->set(UsageAnalyzer::class);
    $container->set(KeyIdentifierAnalyzer::class);
    $container->set(NoneAnalyzer::class);
    $container->set(OctAnalyzer::class);
    $container->set(MixedKeyTypes::class);
    $container->set(MixedPublicAndPrivateKeys::class);
    $container->set(HS256KeyAnalyzer::class);
    $container->set(HS384KeyAnalyzer::class);
    $container->set(HS512KeyAnalyzer::class);
    if (class_exists(NistCurve::class)) {
        $container->set(ES256KeyAnalyzer::class);
        $container->set(ES384KeyAnalyzer::class);
        $container->set(ES512KeyAnalyzer::class);
    }
    if (class_exists(Zxcvbn::class)) {
        $container->set(ZxcvbnKeyAnalyzer::class);
    }
};