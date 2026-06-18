<?php

declare (strict_types=1);
use Pymt_Vas\Dependencies\Jose\Component\KeyManagement\JKUFactory;
use Pymt_Vas\Dependencies\Jose\Component\KeyManagement\X5UFactory;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Loader\Configurator\service;
return function (ContainerConfigurator $container): void {
    $container = $container->services()->defaults()->private()->autoconfigure()->autowire();
    $container->set(JKUFactory::class)->public()->args([service('jose.http_client'), service('jose.request_factory')->nullOnInvalid()]);
    $container->set(X5UFactory::class)->public()->args([service('jose.http_client'), service('jose.request_factory')->nullOnInvalid()]);
};