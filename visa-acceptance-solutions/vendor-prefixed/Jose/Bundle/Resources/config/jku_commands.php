<?php

declare (strict_types=1);
use Pymt_Vas\Dependencies\Jose\Component\Console\JKULoaderCommand;
use Pymt_Vas\Dependencies\Jose\Component\Console\X5ULoaderCommand;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
return function (ContainerConfigurator $container): void {
    $container = $container->services()->defaults()->private()->autoconfigure()->autowire();
    $container->set(JKULoaderCommand::class);
    $container->set(X5ULoaderCommand::class);
};