<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Source\Signature;

use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Source\Source;
use Pymt_Vas\Dependencies\Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\ContainerBuilder;
abstract class AbstractSignatureSource implements Source
{
    public function getNodeDefinition(NodeDefinition $node): void
    {
        $node->children()->arrayNode($this->name())->useAttributeAsKey('name')->arrayPrototype()->children()->booleanNode('is_public')->info('If true, the service will be public, else private.')->defaultTrue()->end()->arrayNode('signature_algorithms')->info('A list of supported signature algorithms.')->useAttributeAsKey('name')->isRequired()->requiresAtLeastOneElement()->scalarPrototype()->end()->end()->arrayNode('tags')->info('A list of tags to be associated to the service.')->useAttributeAsKey('name')->treatNullLike([])->treatFalseLike([])->variablePrototype()->end()->end()->end()->end()->end()->end();
    }
    public function prepend(ContainerBuilder $container, array $config): array
    {
        return [];
    }
}