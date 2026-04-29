<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Source\Encryption;

use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Compiler\CompressionMethodCompilerPass;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Compiler\EncryptionSerializerCompilerPass;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Source\Source;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Source\SourceWithCompilerPasses;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\Algorithm\ContentEncryption\AESCBCHS;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\Algorithm\ContentEncryption\AESGCM;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\Algorithm\KeyEncryption\A128CTR;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\Algorithm\KeyEncryption\AESGCMKW;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\Algorithm\KeyEncryption\AESKW;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\Algorithm\KeyEncryption\Chacha20Poly1305;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\Algorithm\KeyEncryption\Dir;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\Algorithm\KeyEncryption\ECDHES;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\Algorithm\KeyEncryption\PBES2AESKW;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\Algorithm\KeyEncryption\RSA;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\Serializer\JWESerializer as JWESerializerAlias;
use Pymt_Vas\Dependencies\Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Pymt_Vas\Dependencies\Symfony\Component\Config\FileLocator;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\ContainerBuilder;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use function array_key_exists;
use function count;
use function in_array;
class EncryptionSource implements SourceWithCompilerPasses
{
    /**
     * @var Source[]
     */
    private readonly array $sources;
    public function __construct()
    {
        $this->sources = [new JWEBuilder(), new JWEDecrypter(), new JWESerializer(), new JWELoader()];
    }
    public function name(): string
    {
        return 'jwe';
    }
    public function load(array $configs, ContainerBuilder $container): void
    {
        $container->registerForAutoconfiguration(JWESerializerAlias::class)->addTag('jose.jwe.serializer');
        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../../Resources/config'));
        $loader->load('jwe_services.php');
        $loader->load('jwe_serializers.php');
        $loader->load('compression_methods.php');
        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../../Resources/config/Algorithms/'));
        foreach ($this->getAlgorithmsFiles() as $class => $file) {
            if (class_exists($class)) {
                $loader->load($file);
            }
        }
        if (array_key_exists('jwe', $configs)) {
            foreach ($this->sources as $source) {
                $source->load($configs['jwe'], $container);
            }
        }
    }
    public function getNodeDefinition(NodeDefinition $node): void
    {
        $childNode = $node->children()->arrayNode($this->name())->addDefaultsIfNotSet()->treatFalseLike([])->treatNullLike([]);
        foreach ($this->sources as $source) {
            $source->getNodeDefinition($childNode);
        }
    }
    public function prepend(ContainerBuilder $container, array $config): array
    {
        $result = [];
        foreach ($this->sources as $source) {
            $prepend = $source->prepend($container, $config);
            if (count($prepend) !== 0) {
                $result[$source->name()] = $prepend;
            }
        }
        return $result;
    }
    /**
     * @return CompilerPassInterface[]
     */
    public function getCompilerPasses(): array
    {
        return [new EncryptionSerializerCompilerPass(), new CompressionMethodCompilerPass()];
    }
    private function getAlgorithmsFiles(): array
    {
        $list = [AESCBCHS::class => 'encryption_aescbc.php', AESGCM::class => 'encryption_aesgcm.php', AESGCMKW::class => 'encryption_aesgcmkw.php', AESKW::class => 'encryption_aeskw.php', Dir::class => 'encryption_dir.php', ECDHES::class => 'encryption_ecdhes.php', PBES2AESKW::class => 'encryption_pbes2.php', RSA::class => 'encryption_rsa.php', A128CTR::class => 'encryption_experimental.php'];
        if (in_array('chacha20-poly1305', openssl_get_cipher_methods(), true)) {
            $list[Chacha20Poly1305::class] = 'encryption_experimental_chacha20_poly1305.php';
        }
        return $list;
    }
}