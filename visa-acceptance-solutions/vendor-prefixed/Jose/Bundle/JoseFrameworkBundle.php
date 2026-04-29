<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework;

use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Compiler\EventDispatcherAliasCompilerPass;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Compiler\SymfonySerializerCompilerPass;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\JoseFrameworkExtension;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Source;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Source\Checker\CheckerSource;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Source\Console\ConsoleSource;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Source\Core\CoreSource;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Source\Encryption\EncryptionSource;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Source\KeyManagement\KeyManagementSource;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Source\NestedToken\NestedToken;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Source\Signature\SignatureSource;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Source\SourceWithCompilerPasses;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\ContainerBuilder;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Pymt_Vas\Dependencies\Symfony\Component\HttpKernel\Bundle\Bundle;
final class JoseFrameworkBundle extends Bundle
{
    /**
     * @var Source\Source[]
     */
    private array $sources = [];
    public function __construct()
    {
        foreach ($this->getSources() as $source) {
            $this->sources[$source->name()] = $source;
        }
    }
    public function getContainerExtension(): ExtensionInterface
    {
        return new JoseFrameworkExtension('jose', $this->sources);
    }
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        foreach ($this->sources as $source) {
            if ($source instanceof SourceWithCompilerPasses) {
                $compilerPasses = $source->getCompilerPasses();
                foreach ($compilerPasses as $compilerPass) {
                    $container->addCompilerPass($compilerPass, PassConfig::TYPE_BEFORE_OPTIMIZATION, 0);
                }
            }
        }
        $container->addCompilerPass(new EventDispatcherAliasCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 0);
        $container->addCompilerPass(new SymfonySerializerCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 10);
    }
    /**
     * @return Source\Source[]
     */
    private function getSources(): iterable
    {
        return [new CoreSource(), new CheckerSource(), new ConsoleSource(), new SignatureSource(), new EncryptionSource(), new NestedToken(), new KeyManagementSource()];
    }
}