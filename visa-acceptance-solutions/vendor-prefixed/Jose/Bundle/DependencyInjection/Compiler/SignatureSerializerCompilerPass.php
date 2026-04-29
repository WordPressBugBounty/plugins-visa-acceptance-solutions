<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Compiler;

use Pymt_Vas\Dependencies\Jose\Component\Signature\Serializer\JWSSerializerManagerFactory;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\ContainerBuilder;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Reference;
final class SignatureSerializerCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(JWSSerializerManagerFactory::class)) {
            return;
        }
        $definition = $container->getDefinition(JWSSerializerManagerFactory::class);
        $taggedAlgorithmServices = $container->findTaggedServiceIds('jose.jws.serializer');
        foreach ($taggedAlgorithmServices as $id => $tags) {
            $definition->addMethodCall('add', [new Reference($id)]);
        }
    }
}