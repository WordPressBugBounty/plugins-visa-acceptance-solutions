<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Compiler;

use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Serializer\JWEEncoder;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Serializer\JWESerializer;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Serializer\JWSEncoder;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Serializer\JWSSerializer;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\Serializer\JWESerializerManagerFactory;
use Pymt_Vas\Dependencies\Jose\Component\Signature\Serializer\JWSSerializerManagerFactory;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Serializer\Serializer;
class SymfonySerializerCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!class_exists(Serializer::class)) {
            return;
        }
        if ($container->hasDefinition(JWSSerializerManagerFactory::class)) {
            $container->autowire(JWSSerializer::class, JWSSerializer::class)->setPublic(false)->addTag('serializer.normalizer');
            $container->autowire(JWSEncoder::class, JWSEncoder::class)->setPublic(false)->addTag('serializer.encoder');
        }
        if ($container->hasDefinition(JWESerializerManagerFactory::class)) {
            $container->autowire(JWESerializer::class, JWESerializer::class)->setPublic(false)->addTag('serializer.normalizer');
            $container->autowire(JWEEncoder::class, JWEEncoder::class)->setPublic(false)->addTag('serializer.encoder');
        }
    }
}