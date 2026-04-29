<?php

declare (strict_types=1);
use Pymt_Vas\Dependencies\AESKW\Wrapper;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\Algorithm\KeyEncryption\A128KW;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\Algorithm\KeyEncryption\A192KW;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\Algorithm\KeyEncryption\A256KW;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
return function (ContainerConfigurator $container): void {
    $container = $container->services()->defaults()->private()->autoconfigure()->autowire();
    if (interface_exists(Wrapper::class)) {
        $container->set(A128KW::class)->tag('jose.algorithm', ['alias' => 'A128KW']);
        $container->set(A192KW::class)->tag('jose.algorithm', ['alias' => 'A192KW']);
        $container->set(A256KW::class)->tag('jose.algorithm', ['alias' => 'A256KW']);
    }
};