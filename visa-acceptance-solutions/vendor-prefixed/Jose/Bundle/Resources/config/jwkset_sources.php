<?php

declare (strict_types=1);
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Source\KeyManagement\JWKSetSource\JKU;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Source\KeyManagement\JWKSetSource\JWKSet;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Source\KeyManagement\JWKSetSource\X5U;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
return function (ContainerConfigurator $container): void {
    $container = $container->services()->defaults()->public()->autoconfigure()->autowire();
    $container->set(JWKSet::class);
    $container->set(JKU::class);
    $container->set(X5U::class);
};