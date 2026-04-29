<?php

declare (strict_types=1);
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Controller\JWKSetControllerFactory;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Routing\JWKSetLoader;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
return function (ContainerConfigurator $container): void {
    $container = $container->services()->defaults()->private()->autoconfigure()->autowire();
    $container->set(JWKSetControllerFactory::class);
    $container->set(JWKSetLoader::class)->tag('routing.loader');
};