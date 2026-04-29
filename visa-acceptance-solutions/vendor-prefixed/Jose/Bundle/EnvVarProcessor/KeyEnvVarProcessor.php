<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\EnvVarProcessor;

use Closure;
use Pymt_Vas\Dependencies\Jose\Component\Core\JWK;
use Pymt_Vas\Dependencies\Jose\Component\Core\JWKSet;
use RuntimeException;
use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
final class KeyEnvVarProcessor implements EnvVarProcessorInterface
{
    public function getEnv(string $prefix, string $name, Closure $getEnv): mixed
    {
        $env = $getEnv($name);
        return match ($prefix) {
            'jwk' => JWK::createFromJson($env),
            'jwkset' => JWKSet::createFromJson($env),
            default => throw new RuntimeException(sprintf('Unsupported prefix "%s".', $prefix)),
        };
    }
    public static function getProvidedTypes(): array
    {
        return ['jwk' => 'string', 'jwkset' => 'string'];
    }
}