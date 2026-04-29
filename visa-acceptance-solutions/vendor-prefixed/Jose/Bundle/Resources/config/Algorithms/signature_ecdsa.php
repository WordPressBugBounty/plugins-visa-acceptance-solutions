<?php

declare (strict_types=1);
use Pymt_Vas\Dependencies\Jose\Component\Signature\Algorithm\ES256;
use Pymt_Vas\Dependencies\Jose\Component\Signature\Algorithm\ES384;
use Pymt_Vas\Dependencies\Jose\Component\Signature\Algorithm\ES512;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
return function (ContainerConfigurator $container): void {
    $container = $container->services()->defaults()->private()->autoconfigure()->autowire();
    $container->set(ES256::class)->tag('jose.algorithm', ['alias' => 'ES256']);
    $container->set(ES384::class)->tag('jose.algorithm', ['alias' => 'ES384']);
    $container->set(ES512::class)->tag('jose.algorithm', ['alias' => 'ES512']);
};