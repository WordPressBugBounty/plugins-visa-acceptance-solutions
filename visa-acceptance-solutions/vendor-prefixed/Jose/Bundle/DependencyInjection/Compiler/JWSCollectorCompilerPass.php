<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Compiler;

use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DataCollector\JWSCollector;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\ContainerBuilder;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Definition;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Reference;
final class JWSCollectorCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(JWSCollector::class)) {
            return;
        }
        $definition = $container->getDefinition(JWSCollector::class);
        $services = ['addJWSBuilder' => 'jose.jws_builder', 'addJWSVerifier' => 'jose.jws_verifier', 'addJWSLoader' => 'jose.jws_loader'];
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