<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Compiler;

use InvalidArgumentException;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Services\ClaimCheckerManagerFactory;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\ContainerBuilder;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Reference;
final class ClaimCheckerCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ClaimCheckerManagerFactory::class)) {
            return;
        }
        $definition = $container->getDefinition(ClaimCheckerManagerFactory::class);
        $taggedClaimCheckerServices = $container->findTaggedServiceIds('jose.checker.claim');
        foreach ($taggedClaimCheckerServices as $id => $tags) {
            foreach ($tags as $attributes) {
                if (!isset($attributes['alias'])) {
                    throw new InvalidArgumentException(sprintf('The claim checker "%s" does not have any "alias" attribute.', $id));
                }
                $definition->addMethodCall('add', [$attributes['alias'], new Reference($id)]);
            }
        }
    }
}