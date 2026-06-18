<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Services;

use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Event\NestedTokenIssuedEvent;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\JWEBuilder;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\Serializer\JWESerializerManager;
use Pymt_Vas\Dependencies\Jose\Component\NestedToken\NestedTokenBuilder as BaseNestedTokenBuilder;
use Pymt_Vas\Dependencies\Jose\Component\Signature\JWSBuilder;
use Pymt_Vas\Dependencies\Jose\Component\Signature\Serializer\JWSSerializerManager;
use Pymt_Vas\Dependencies\Psr\EventDispatcher\EventDispatcherInterface;
final class NestedTokenBuilder extends BaseNestedTokenBuilder
{
    public function __construct(JWEBuilder $jweBuilder, JWESerializerManager $jweSerializerManager, JWSBuilder $jwsBuilder, JWSSerializerManager $jwsSerializerManager, private readonly EventDispatcherInterface $eventDispatcher)
    {
        parent::__construct($jweBuilder, $jweSerializerManager, $jwsBuilder, $jwsSerializerManager);
    }
    public function create(string $payload, array $signatures, string $jws_serialization_mode, array $jweSharedProtectedHeader, array $jweSharedHeader, array $recipients, string $jwe_serialization_mode, ?string $aad = null): string
    {
        $nestedToken = parent::create($payload, $signatures, $jws_serialization_mode, $jweSharedProtectedHeader, $jweSharedHeader, $recipients, $jwe_serialization_mode, $aad);
        $this->eventDispatcher->dispatch(new NestedTokenIssuedEvent($nestedToken));
        return $nestedToken;
    }
}