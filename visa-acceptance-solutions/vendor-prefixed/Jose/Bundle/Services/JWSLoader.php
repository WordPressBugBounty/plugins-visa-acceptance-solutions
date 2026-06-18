<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Services;

use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Event\JWSLoadingFailureEvent;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Event\JWSLoadingSuccessEvent;
use Pymt_Vas\Dependencies\Jose\Component\Checker\HeaderCheckerManager;
use Pymt_Vas\Dependencies\Jose\Component\Core\JWKSet;
use Pymt_Vas\Dependencies\Jose\Component\Signature\JWS;
use Pymt_Vas\Dependencies\Jose\Component\Signature\JWSLoader as BaseJWSLoader;
use Pymt_Vas\Dependencies\Jose\Component\Signature\JWSVerifier;
use Pymt_Vas\Dependencies\Jose\Component\Signature\Serializer\JWSSerializerManager;
use Pymt_Vas\Dependencies\Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;
final class JWSLoader extends BaseJWSLoader
{
    public function __construct(JWSSerializerManager $serializerManager, JWSVerifier $jwsVerifier, ?HeaderCheckerManager $headerCheckerManager, private readonly EventDispatcherInterface $eventDispatcher)
    {
        parent::__construct($serializerManager, $jwsVerifier, $headerCheckerManager);
    }
    public function loadAndVerifyWithKeySet(string $token, JWKSet $keyset, ?int &$signature, ?string $payload = null): JWS
    {
        try {
            $jws = parent::loadAndVerifyWithKeySet($token, $keyset, $signature, $payload);
            $this->eventDispatcher->dispatch(new JWSLoadingSuccessEvent($token, $jws, $keyset, $signature));
            return $jws;
        } catch (Throwable $throwable) {
            $this->eventDispatcher->dispatch(new JWSLoadingFailureEvent($token, $keyset, $throwable));
            throw $throwable;
        }
    }
}