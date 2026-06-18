<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Extension;

use Symfony\Component\Config\Builder\ConfigBuilderGenerator;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\DelegatingLoader;
use Symfony\Component\Config\Loader\LoaderResolver;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\ContainerBuilder;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Loader\ClosureLoader;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Loader\DirectoryLoader;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Loader\GlobFileLoader;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Loader\IniFileLoader;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
/**
 * @author Yonel Ceruto <yonelceruto@gmail.com>
 */
trait ExtensionTrait
{
    private function executeConfiguratorCallback(ContainerBuilder $container, \Closure $callback, ConfigurableExtensionInterface $subject, bool $prepend = false): void
    {
        $env = $container->getParameter('kernel.environment');
        $loader = $this->createContainerLoader($container, $env, $prepend);
        $file = (new \ReflectionObject($subject))->getFileName();
        $bundleLoader = $loader->getResolver()->resolve($file);
        if (!$bundleLoader instanceof PhpFileLoader) {
            throw new \LogicException('Unable to create the ContainerConfigurator.');
        }
        $bundleLoader->setCurrentDir(\dirname($file));
        $instanceof =& \Closure::bind(fn&() => $this->instanceof, $bundleLoader, $bundleLoader)();
        try {
            $callback(new ContainerConfigurator($container, $bundleLoader, $instanceof, $file, $file, $env));
        } finally {
            $instanceof = [];
            $bundleLoader->registerAliasesForSinglyImplementedInterfaces();
        }
    }
    private function createContainerLoader(ContainerBuilder $container, string $env, bool $prepend): DelegatingLoader
    {
        $buildDir = $container->getParameter('kernel.build_dir');
        $locator = new FileLocator();
        $resolver = new LoaderResolver([new XmlFileLoader($container, $locator, $env, $prepend), new YamlFileLoader($container, $locator, $env, $prepend), new IniFileLoader($container, $locator, $env), class_exists(ConfigBuilderGenerator::class) ? new PhpFileLoader($container, $locator, $env, new ConfigBuilderGenerator($buildDir), $prepend) : new PhpFileLoader($container, $locator, $env, $prepend), new GlobFileLoader($container, $locator, $env), new DirectoryLoader($container, $locator, $env), new ClosureLoader($container, $env)]);
        return new DelegatingLoader($resolver);
    }
}