<?php

declare (strict_types=1);
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Services\JWSBuilderFactory;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Services\JWSLoaderFactory;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Services\JWSVerifierFactory;
use Pymt_Vas\Dependencies\Jose\Component\Signature\JWSTokenSupport;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
return function (ContainerConfigurator $container): void {
    $container = $container->services()->defaults()->private()->autoconfigure()->autowire();
    $container->set(JWSBuilderFactory::class)->public();
    $container->set(JWSVerifierFactory::class)->public();
    $container->set(JWSLoaderFactory::class)->public();
    $container->set(JWSTokenSupport::class);
};