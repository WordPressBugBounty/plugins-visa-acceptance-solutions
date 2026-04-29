<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Component\Console;

use InvalidArgumentException;
use Pymt_Vas\Dependencies\Jose\Component\Core\JWK;
use Pymt_Vas\Dependencies\Jose\Component\Core\Util\JsonConverter;
use Pymt_Vas\Dependencies\Symfony\Component\Console\Attribute\AsCommand;
use Pymt_Vas\Dependencies\Symfony\Component\Console\Input\InputArgument;
use Pymt_Vas\Dependencies\Symfony\Component\Console\Input\InputInterface;
use Pymt_Vas\Dependencies\Symfony\Component\Console\Output\OutputInterface;
use function is_array;
use function is_string;
#[AsCommand(name: 'key:convert:public', description: 'Convert a private key into public key. Symmetric keys (shared keys) are not changed.')]
final class PublicKeyCommand extends ObjectOutputCommand
{
    protected static $defaultName = 'key:convert:public';
    protected static $defaultDescription = 'Convert a private key into public key. Symmetric keys (shared keys) are not changed.';
    protected function configure(): void
    {
        parent::configure();
        $this->setHelp('This command converts a private key into a public key.')->addArgument('jwk', InputArgument::REQUIRED, 'The JWK object');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jwk = $this->getKey($input);
        $jwk = $jwk->toPublic();
        $this->prepareJsonOutput($input, $output, $jwk);
        return self::SUCCESS;
    }
    private function getKey(InputInterface $input): JWK
    {
        $jwk = $input->getArgument('jwk');
        if (!is_string($jwk)) {
            throw new InvalidArgumentException('Invalid JWK');
        }
        $json = JsonConverter::decode($jwk);
        if (!is_array($json)) {
            throw new InvalidArgumentException('Invalid JWK');
        }
        return new JWK($json);
    }
}