<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Compiler;

use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DataCollector\JWECollector;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\ContainerBuilder;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Definition;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Reference;
final class JWECollectorCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(JWECollector::class)) {
            return;
        }
        $definition = $container->getDefinition(JWECollector::class);
        $services = ['addJWEBuilder' => 'jose.jwe_builder', 'addJWEDecrypter' => 'jose.jwe_decrypter', 'addJWELoader' => 'jose.jwe_loader'];
        foreach ($services as $method => $tag) {
            $this->collectServices($method, $tag, $definition, $container);
        }
    }
    private function collectServices(string $method, string $tag, Definition $definition, ContainerBuilder $container): void
    {
        $taggedJWEServices = $container->findTaggedServiceIds($tag);
        foreach ($taggedJWEServices as $id => $tags) {
            $definition->addMethodCall($method, [$id, new Reference($id)]);
        }
    }
}