<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Compiler;

use Pymt_Vas\Dependencies\Jose\Component\KeyManagement\Analyzer\KeysetAnalyzerManager;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\ContainerBuilder;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Reference;
final class KeysetAnalyzerCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(KeysetAnalyzerManager::class)) {
            return;
        }
        $definition = $container->getDefinition(KeysetAnalyzerManager::class);
        $taggedServices = $container->findTaggedServiceIds('jose.keyset_analyzer');
        foreach ($taggedServices as $id => $tags) {
            $definition->addMethodCall('add', [new Reference($id)]);
        }
    }
}