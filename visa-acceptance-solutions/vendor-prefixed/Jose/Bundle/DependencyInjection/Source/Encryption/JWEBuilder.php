<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Source\Encryption;

use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Services\JWEBuilderFactory;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\JWEBuilder as JWEBuilderService;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\ContainerBuilder;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Definition;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Reference;
class JWEBuilder extends AbstractEncryptionSource
{
    public function name(): string
    {
        return 'builders';
    }
    public function load(array $configs, ContainerBuilder $container): void
    {
        foreach ($configs[$this->name()] as $name => $itemConfig) {
            $service_id = sprintf('jose.jwe_builder.%s', $name);
            $definition = new Definition(JWEBuilderService::class);
            $definition->setFactory([new Reference(JWEBuilderFactory::class), 'create'])->setArguments([$itemConfig['encryption_algorithms'], null, $itemConfig['compression_methods'] === [] ? null : $itemConfig['compression_methods']])->addTag('jose.jwe_builder')->setPublic($itemConfig['is_public']);
            foreach ($itemConfig['tags'] as $id => $attributes) {
                $definition->addTag($id, $attributes);
            }
            $container->setDefinition($service_id, $definition);
            $container->registerAliasForArgument($service_id, JWEBuilderService::class, $name . 'JweBuilder');
        }
    }
}