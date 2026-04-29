<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DataCollector;

use Pymt_Vas\Dependencies\Symfony\Component\HttpFoundation\Request;
use Pymt_Vas\Dependencies\Symfony\Component\HttpFoundation\Response;
use Throwable;
interface Collector
{
    /**
     * @param array<string, mixed> $data
     */
    public function collect(array &$data, Request $request, Response $response, ?Throwable $exception = null): void;
}