<?php

declare (strict_types=1);
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Services\ClaimCheckerManagerFactory;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Services\HeaderCheckerManagerFactory;
use Pymt_Vas\Dependencies\Jose\Component\Checker\ExpirationTimeChecker;
use Pymt_Vas\Dependencies\Jose\Component\Checker\InternalClock;
use Pymt_Vas\Dependencies\Jose\Component\Checker\IssuedAtChecker;
use Pymt_Vas\Dependencies\Jose\Component\Checker\NotBeforeChecker;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Loader\Configurator\service;
return function (ContainerConfigurator $container): void {
    $container = $container->services()->defaults()->private()->autoconfigure()->autowire();
    $container->set(HeaderCheckerManagerFactory::class)->public();
    $container->set(ClaimCheckerManagerFactory::class)->public();
    $container->set(ExpirationTimeChecker::class)->arg('$clock', service('jose.internal_clock'))->tag('jose.checker.claim', ['alias' => 'exp'])->tag('jose.checker.header', ['alias' => 'exp']);
    $container->set(IssuedAtChecker::class)->arg('$clock', service('jose.internal_clock'))->tag('jose.checker.claim', ['alias' => 'iat'])->tag('jose.checker.header', ['alias' => 'iat']);
    $container->set(NotBeforeChecker::class)->arg('$clock', service('jose.internal_clock'))->tag('jose.checker.claim', ['alias' => 'nbf'])->tag('jose.checker.header', ['alias' => 'nbf']);
    $container->set('jose.internal_clock')->class(InternalClock::class)->deprecate('web-token/jwt-bundle', '3.2.0', 'The service "%service_id%" is an internal service that will be removed in 4.0.0. Please use a PSR-20 compatible service as clock.')->private();
};