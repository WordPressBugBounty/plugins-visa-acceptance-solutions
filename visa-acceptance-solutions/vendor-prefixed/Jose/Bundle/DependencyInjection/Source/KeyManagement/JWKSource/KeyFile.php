<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Source\KeyManagement\JWKSource;

use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Source\AbstractSource;
use Pymt_Vas\Dependencies\Jose\Component\Core\JWK;
use Pymt_Vas\Dependencies\Jose\Component\KeyManagement\JWKFactory;
use Pymt_Vas\Dependencies\Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\ContainerBuilder;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Definition;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Reference;
class KeyFile extends AbstractSource implements JWKSource
{
    /**
     * @param array<string, mixed> $config
     */
    public function createDefinition(ContainerBuilder $container, array $config): Definition
    {
        $definition = new Definition(JWK::class);
        $definition->setFactory([new Reference(JWKFactory::class), 'createFromKeyFile']);
        $definition->setArguments([$config['path'], $config['password'], $config['additional_values']]);
        $definition->addTag('jose.jwk');
        return $definition;
    }
    public function getKey(): string
    {
        return 'file';
    }
    public function addConfiguration(NodeDefinition $node): void
    {
        parent::addConfiguration($node);
        $node->children()->scalarNode('path')->info('Path of the key file.')->isRequired()->end()->scalarNode('password')->info('Password used to decrypt the key (optional).')->defaultNull()->end()->arrayNode('additional_values')->info('Additional values to be added to the key.')->defaultValue([])->useAttributeAsKey('key')->variablePrototype()->end()->end()->end();
    }
}