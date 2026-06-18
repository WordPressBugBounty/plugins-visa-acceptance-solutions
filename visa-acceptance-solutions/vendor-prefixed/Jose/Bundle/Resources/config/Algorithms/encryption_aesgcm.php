<?php

declare (strict_types=1);
use Pymt_Vas\Dependencies\Jose\Component\Encryption\Algorithm\ContentEncryption\A128GCM;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\Algorithm\ContentEncryption\A192GCM;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\Algorithm\ContentEncryption\A256GCM;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
return function (ContainerConfigurator $container): void {
    $container = $container->services()->defaults()->private()->autoconfigure()->autowire();
    $container->set(A128GCM::class)->tag('jose.algorithm', ['alias' => 'A128GCM']);
    $container->set(A192GCM::class)->tag('jose.algorithm', ['alias' => 'A192GCM']);
    $container->set(A256GCM::class)->tag('jose.algorithm', ['alias' => 'A256GCM']);
};