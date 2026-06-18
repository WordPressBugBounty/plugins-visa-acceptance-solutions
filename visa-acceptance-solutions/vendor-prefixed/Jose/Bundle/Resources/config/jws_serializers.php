<?php

declare (strict_types=1);
use Pymt_Vas\Dependencies\Jose\Component\Signature\Serializer\CompactSerializer;
use Pymt_Vas\Dependencies\Jose\Component\Signature\Serializer\JSONFlattenedSerializer;
use Pymt_Vas\Dependencies\Jose\Component\Signature\Serializer\JSONGeneralSerializer;
use Pymt_Vas\Dependencies\Jose\Component\Signature\Serializer\JWSSerializerManagerFactory;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
return function (ContainerConfigurator $container): void {
    $container = $container->services()->defaults()->private()->autoconfigure()->autowire();
    $container->set(JWSSerializerManagerFactory::class)->public();
    $container->set(CompactSerializer::class);
    $container->set(JSONFlattenedSerializer::class);
    $container->set(JSONGeneralSerializer::class);
};