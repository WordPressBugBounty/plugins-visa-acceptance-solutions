<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Source\Core;

use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DataCollector\Collector;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Compiler\AlgorithmCompilerPass;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Compiler\CheckerCollectorCompilerPass;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Compiler\DataCollectorCompilerPass;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Compiler\JWECollectorCompilerPass;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Compiler\JWSCollectorCompilerPass;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Compiler\KeyCollectorCompilerPass;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Source\SourceWithCompilerPasses;
use Pymt_Vas\Dependencies\Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Pymt_Vas\Dependencies\Symfony\Component\Config\FileLocator;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\ContainerBuilder;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
class CoreSource implements SourceWithCompilerPasses
{
    public function name(): string
    {
        return 'core';
    }
    public function load(array $config, ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../../Resources/config'));
        $loader->load('services.php');
        if (interface_exists(EnvVarProcessorInterface::class)) {
            $loader->load('env_var.php');
        }
        if ($container->getParameter('kernel.debug') === true) {
            $container->registerForAutoconfiguration(Collector::class)->addTag('jose.data_collector');
            $loader->load('dev_services.php');
        }
    }
    public function getNodeDefinition(NodeDefinition $node): void
    {
    }
    public function prepend(ContainerBuilder $container, array $config): array
    {
        return [];
    }
    /**
     * @return CompilerPassInterface[]
     */
    public function getCompilerPasses(): array
    {
        return [new AlgorithmCompilerPass(), new DataCollectorCompilerPass(), new CheckerCollectorCompilerPass(), new KeyCollectorCompilerPass(), new JWSCollectorCompilerPass(), new JWECollectorCompilerPass()];
    }
}