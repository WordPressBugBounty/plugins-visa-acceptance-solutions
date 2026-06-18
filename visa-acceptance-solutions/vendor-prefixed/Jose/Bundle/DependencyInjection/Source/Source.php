<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Source;

use Pymt_Vas\Dependencies\Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\ContainerBuilder;
interface Source
{
    public function name(): string;
    public function load(array $configs, ContainerBuilder $container): void;
    public function getNodeDefinition(NodeDefinition $node): void;
    public function prepend(ContainerBuilder $container, array $config): array;
}