<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Component\Console;

use InvalidArgumentException;
use Pymt_Vas\Dependencies\Jose\Component\Core\JWK;
use Pymt_Vas\Dependencies\Jose\Component\Core\Util\JsonConverter;
use Pymt_Vas\Dependencies\Jose\Component\KeyManagement\KeyConverter\RSAKey;
use Pymt_Vas\Dependencies\Symfony\Component\Console\Attribute\AsCommand;
use Pymt_Vas\Dependencies\Symfony\Component\Console\Input\InputArgument;
use Pymt_Vas\Dependencies\Symfony\Component\Console\Input\InputInterface;
use Pymt_Vas\Dependencies\Symfony\Component\Console\Output\OutputInterface;
use function is_array;
use function is_string;
#[AsCommand(name: 'key:optimize', description: 'Optimize a RSA key by calculating additional primes (CRT).')]
final class OptimizeRsaKeyCommand extends ObjectOutputCommand
{
    protected static $defaultName = 'key:optimize';
    protected static $defaultDescription = 'Optimize a RSA key by calculating additional primes (CRT).';
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('jwk', InputArgument::REQUIRED, 'The RSA key.');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jwk = $input->getArgument('jwk');
        if (!is_string($jwk)) {
            throw new InvalidArgumentException('Invalid JWK');
        }
        $json = JsonConverter::decode($jwk);
        if (!is_array($json)) {
            throw new InvalidArgumentException('Invalid JWK');
        }
        $key = RSAKey::createFromJWK(new JWK($json));
        $key->optimize();
        $this->prepareJsonOutput($input, $output, $key->toJwk());
        return self::SUCCESS;
    }
}