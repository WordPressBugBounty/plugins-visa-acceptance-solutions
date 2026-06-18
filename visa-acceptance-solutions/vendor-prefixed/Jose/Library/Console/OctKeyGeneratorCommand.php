<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Component\Console;

use InvalidArgumentException;
use Pymt_Vas\Dependencies\Jose\Component\KeyManagement\JWKFactory;
use Pymt_Vas\Dependencies\Symfony\Component\Console\Attribute\AsCommand;
use Pymt_Vas\Dependencies\Symfony\Component\Console\Input\InputArgument;
use Pymt_Vas\Dependencies\Symfony\Component\Console\Input\InputInterface;
use Pymt_Vas\Dependencies\Symfony\Component\Console\Output\OutputInterface;
#[AsCommand(name: 'key:generate:oct', description: 'Generate an octet key (JWK format)')]
final class OctKeyGeneratorCommand extends GeneratorCommand
{
    protected static $defaultName = 'key:generate:oct';
    protected static $defaultDescription = 'Generate an octet key (JWK format)';
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('size', InputArgument::REQUIRED, 'Key size.');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $size = (int) $input->getArgument('size');
        if ($size < 1) {
            throw new InvalidArgumentException('Invalid size');
        }
        $args = $this->getOptions($input);
        $jwk = JWKFactory::createOctKey($size, $args);
        $this->prepareJsonOutput($input, $output, $jwk);
        return self::SUCCESS;
    }
}