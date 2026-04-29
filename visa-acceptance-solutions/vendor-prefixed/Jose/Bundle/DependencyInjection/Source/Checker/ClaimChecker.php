<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Source\Checker;

use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Source\Source;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Services\ClaimCheckerManager;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Services\ClaimCheckerManagerFactory;
use Pymt_Vas\Dependencies\Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\ContainerBuilder;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Definition;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Reference;
class ClaimChecker implements Source
{
    public function name(): string
    {
        return 'claims';
    }
    public function load(array $configs, ContainerBuilder $container): void
    {
        foreach ($configs[$this->name()] as $name => $itemConfig) {
            $service_id = sprintf('jose.claim_checker.%s', $name);
            $definition = new Definition(ClaimCheckerManager::class);
            $definition->setFactory([new Reference(ClaimCheckerManagerFactory::class), 'create'])->setArguments([$itemConfig['claims']])->addTag('jose.claim_checker_manager')->setPublic($itemConfig['is_public']);
            foreach ($itemConfig['tags'] as $id => $attributes) {
                $definition->addTag($id, $attributes);
            }
            $container->setDefinition($service_id, $definition);
            $container->registerAliasForArgument($service_id, ClaimCheckerManager::class, $name . 'ClaimCheckerManager');
        }
    }
    public function getNodeDefinition(NodeDefinition $node): void
    {
        $node->children()->arrayNode($this->name())->treatFalseLike([])->treatNullLike([])->useAttributeAsKey('name')->arrayPrototype()->children()->booleanNode('is_public')->info('If true, the service will be public, else private.')->defaultTrue()->end()->arrayNode('claims')->info('A list of claim aliases to be set in the claim checker.')->useAttributeAsKey('name')->isRequired()->scalarPrototype()->end()->end()->arrayNode('tags')->info('A list of tags to be associated to the claim checker.')->useAttributeAsKey('name')->treatNullLike([])->treatFalseLike([])->variablePrototype()->end()->end()->end()->end()->end()->end();
    }
    public function prepend(ContainerBuilder $container, array $config): array
    {
        return [];
    }
}