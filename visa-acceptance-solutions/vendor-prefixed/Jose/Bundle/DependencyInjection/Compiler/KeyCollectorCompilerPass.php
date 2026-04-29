<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Compiler;

use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DataCollector\KeyCollector;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\ContainerBuilder;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Definition;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Reference;
final class KeyCollectorCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(KeyCollector::class)) {
            return;
        }
        $definition = $container->getDefinition(KeyCollector::class);
        $services = ['addJWK' => 'jose.jwk', 'addJWKSet' => 'jose.jwkset'];
        foreach ($services as $method => $tag) {
            $this->collectServices($method, $tag, $definition, $container);
        }
    }
    private function collectServices(string $method, string $tag, Definition $definition, ContainerBuilder $container): void
    {
        $taggedJWSServices = $container->findTaggedServiceIds($tag);
        foreach ($taggedJWSServices as $id => $tags) {
            $definition->addMethodCall($method, [$id, new Reference($id)]);
        }
    }
}