<?php

declare (strict_types=1);
use Pymt_Vas\Dependencies\Jose\Component\KeyManagement\JWKFactory;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
return function (ContainerConfigurator $container): void {
    $container = $container->services()->defaults()->private()->autoconfigure()->autowire();
    $container->set(JWKFactory::class)->public();
};