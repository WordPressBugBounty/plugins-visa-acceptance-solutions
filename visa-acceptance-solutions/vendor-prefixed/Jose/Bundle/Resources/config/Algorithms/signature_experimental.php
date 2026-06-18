<?php

declare (strict_types=1);
use Pymt_Vas\Dependencies\Jose\Component\Signature\Algorithm\Blake2b;
use Pymt_Vas\Dependencies\Jose\Component\Signature\Algorithm\ES256K;
use Pymt_Vas\Dependencies\Jose\Component\Signature\Algorithm\HS1;
use Pymt_Vas\Dependencies\Jose\Component\Signature\Algorithm\HS256_64;
use Pymt_Vas\Dependencies\Jose\Component\Signature\Algorithm\RS1;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
/*
 * ---- New algorithms ----
 * These algorithms are out of the main specifications but referenced in
 * some WebAuthn documents.
 *
 * They may be subject to changes.
 * ------------------------
 */
return function (ContainerConfigurator $container): void {
    $container = $container->services()->defaults()->private()->autoconfigure()->autowire();
    $container->set(RS1::class)->tag('jose.algorithm', ['alias' => 'RS1']);
    $container->set(HS1::class)->tag('jose.algorithm', ['alias' => 'HS1']);
    $container->set(HS256_64::class)->tag('jose.algorithm', ['alias' => 'HS256/64']);
    $container->set(ES256K::class)->tag('jose.algorithm', ['alias' => 'ES256K']);
    $container->set(Blake2b::class)->tag('jose.algorithm', ['alias' => 'BLAKE2B']);
};