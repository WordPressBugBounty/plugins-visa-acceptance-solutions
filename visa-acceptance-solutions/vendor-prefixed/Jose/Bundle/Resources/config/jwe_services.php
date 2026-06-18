<?php

declare (strict_types=1);
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Services\JWEBuilderFactory;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Services\JWEDecrypterFactory;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Services\JWELoaderFactory;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\JWETokenSupport;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
return function (ContainerConfigurator $container): void {
    $container = $container->services()->defaults()->private()->autoconfigure()->autowire();
    $container->set(JWEBuilderFactory::class)->public();
    $container->set(JWEDecrypterFactory::class)->public();
    $container->set(JWELoaderFactory::class)->public();
    $container->set(JWETokenSupport::class);
};