<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Component\Console;

use Pymt_Vas\Dependencies\Jose\Component\Core\Util\JsonConverter;
use JsonSerializable;
use Pymt_Vas\Dependencies\Symfony\Component\Console\Command\Command;
use Pymt_Vas\Dependencies\Symfony\Component\Console\Input\InputInterface;
use Pymt_Vas\Dependencies\Symfony\Component\Console\Output\OutputInterface;
abstract class ObjectOutputCommand extends Command
{
    protected function prepareJsonOutput(InputInterface $input, OutputInterface $output, JsonSerializable $json): void
    {
        $data = JsonConverter::encode($json);
        $output->write($data);
    }
}