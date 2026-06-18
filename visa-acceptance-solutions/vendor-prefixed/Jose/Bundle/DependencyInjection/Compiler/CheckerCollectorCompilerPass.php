<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Compiler;

use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DataCollector\CheckerCollector;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\ContainerBuilder;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Definition;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Reference;
final class CheckerCollectorCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(CheckerCollector::class)) {
            return;
        }
        $definition = $container->getDefinition(CheckerCollector::class);
        $services = ['addHeaderCheckerManager' => 'jose.header_checker_manager', 'addClaimCheckerManager' => 'jose.claim_checker_manager'];
        foreach ($services as $method => $tag) {
            $this->collectServices($method, $tag, $definition, $container);
        }
    }
    private function collectServices(string $method, string $tag, Definition $definition, ContainerBuilder $container): void
    {
        $taggedCheckerServices = $container->findTaggedServiceIds($tag);
        foreach ($taggedCheckerServices as $id => $tags) {
            $definition->addMethodCall($method, [$id, new Reference($id)]);
        }
    }
}