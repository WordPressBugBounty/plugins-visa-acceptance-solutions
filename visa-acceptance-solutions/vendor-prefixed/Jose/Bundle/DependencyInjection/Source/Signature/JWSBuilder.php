<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Source\Signature;

use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Services\JWSBuilderFactory;
use Pymt_Vas\Dependencies\Jose\Component\Signature\JWSBuilder as JWSBuilderService;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\ContainerBuilder;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Definition;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Reference;
class JWSBuilder extends AbstractSignatureSource
{
    public function name(): string
    {
        return 'builders';
    }
    public function load(array $configs, ContainerBuilder $container): void
    {
        foreach ($configs[$this->name()] as $name => $itemConfig) {
            $service_id = sprintf('jose.jws_builder.%s', $name);
            $definition = new Definition(JWSBuilderService::class);
            $definition->setFactory([new Reference(JWSBuilderFactory::class), 'create'])->setArguments([$itemConfig['signature_algorithms']])->addTag('jose.jws_builder')->setPublic($itemConfig['is_public']);
            foreach ($itemConfig['tags'] as $id => $attributes) {
                $definition->addTag($id, $attributes);
            }
            $container->setDefinition($service_id, $definition);
            $container->registerAliasForArgument($service_id, JWSBuilderService::class, $name . 'JwsBuilder');
        }
    }
}