<?php

declare (strict_types=1);
use Pymt_Vas\Dependencies\Jose\Component\Signature\Algorithm\None;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
return function (ContainerConfigurator $container): void {
    $container = $container->services()->defaults()->private()->autoconfigure()->autowire();
    $container->set(None::class)->tag('jose.algorithm', ['alias' => 'none']);
};