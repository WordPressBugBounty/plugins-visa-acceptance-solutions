<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Compiler;

use Pymt_Vas\Dependencies\Psr\EventDispatcher\EventDispatcherInterface;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\ContainerBuilder;
final class EventDispatcherAliasCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('event_dispatcher') || $container->hasAlias(EventDispatcherInterface::class)) {
            return;
        }
        $container->setAlias(EventDispatcherInterface::class, 'event_dispatcher');
    }
}