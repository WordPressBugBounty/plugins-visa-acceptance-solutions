<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Component\Checker;

use DateTimeImmutable;
use Pymt_Vas\Dependencies\Psr\Clock\ClockInterface;
/**
 * @internal
 */
final class InternalClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}