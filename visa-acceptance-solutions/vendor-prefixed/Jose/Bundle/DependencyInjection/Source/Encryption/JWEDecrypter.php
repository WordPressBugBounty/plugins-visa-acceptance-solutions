<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Source\Encryption;

use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Services\JWEDecrypterFactory;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\JWEDecrypter as JWEDecrypterService;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\ContainerBuilder;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Definition;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Reference;
class JWEDecrypter extends AbstractEncryptionSource
{
    public function name(): string
    {
        return 'decrypters';
    }
    public function load(array $configs, ContainerBuilder $container): void
    {
        foreach ($configs[$this->name()] as $name => $itemConfig) {
            $service_id = sprintf('jose.jwe_decrypter.%s', $name);
            $definition = new Definition(JWEDecrypterService::class);
            $definition->setFactory([new Reference(JWEDecrypterFactory::class), 'create'])->setArguments([$itemConfig['encryption_algorithms'], null, $itemConfig['compression_methods'] === [] ? null : $itemConfig['compression_methods']])->addTag('jose.jwe_decrypter')->setPublic($itemConfig['is_public']);
            foreach ($itemConfig['tags'] as $id => $attributes) {
                $definition->addTag($id, $attributes);
            }
            $container->setDefinition($service_id, $definition);
            $container->registerAliasForArgument($service_id, JWEDecrypterService::class, $name . 'JweDecrypter');
        }
    }
}