<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Source\KeyManagement;

use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Compiler\KeyAnalyzerCompilerPass;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Compiler\KeysetAnalyzerCompilerPass;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Compiler\KeySetControllerCompilerPass;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Source\Source;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Source\SourceWithCompilerPasses;
use Pymt_Vas\Dependencies\Jose\Component\KeyManagement\Analyzer\KeyAnalyzer;
use Pymt_Vas\Dependencies\Jose\Component\KeyManagement\Analyzer\KeysetAnalyzer;
use Pymt_Vas\Dependencies\Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Pymt_Vas\Dependencies\Symfony\Component\Config\FileLocator;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\ContainerBuilder;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use function count;
class KeyManagementSource implements SourceWithCompilerPasses
{
    /**
     * @var Source[]
     */
    private readonly array $sources;
    public function __construct()
    {
        $this->sources = [new JWKSetSource(), new JWKSource(), new JWKUriSource(), new JKUSource()];
    }
    public function name(): string
    {
        return 'key_mgmt';
    }
    public function load(array $configs, ContainerBuilder $container): void
    {
        $container->registerForAutoconfiguration(KeyAnalyzer::class)->addTag('jose.key_analyzer');
        $container->registerForAutoconfiguration(KeysetAnalyzer::class)->addTag('jose.keyset_analyzer');
        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../../Resources/config'));
        $loader->load('analyzers.php');
        $loader->load('jwk_factory.php');
        $loader->load('jwk_services.php');
        foreach ($this->sources as $source) {
            $source->load($configs, $container);
        }
    }
    public function getNodeDefinition(NodeDefinition $node): void
    {
        foreach ($this->sources as $source) {
            $source->getNodeDefinition($node);
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
        return [new KeyAnalyzerCompilerPass(), new KeysetAnalyzerCompilerPass(), new KeySetControllerCompilerPass()];
    }
}