<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Services;

use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Event\JWSVerificationFailureEvent;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Event\JWSVerificationSuccessEvent;
use Pymt_Vas\Dependencies\Jose\Component\Core\AlgorithmManager;
use Pymt_Vas\Dependencies\Jose\Component\Core\JWK;
use Pymt_Vas\Dependencies\Jose\Component\Core\JWKSet;
use Pymt_Vas\Dependencies\Jose\Component\Signature\JWS;
use Pymt_Vas\Dependencies\Jose\Component\Signature\JWSVerifier as BaseJWSVerifier;
use Pymt_Vas\Dependencies\Psr\EventDispatcher\EventDispatcherInterface;
final class JWSVerifier extends BaseJWSVerifier
{
    public function __construct(AlgorithmManager $signatureAlgorithmManager, private readonly EventDispatcherInterface $eventDispatcher)
    {
        parent::__construct($signatureAlgorithmManager);
    }
    public function verifyWithKeySet(JWS $jws, JWKSet $jwkset, int $signatureIndex, ?string $detachedPayload = null, ?JWK &$jwk = null): bool
    {
        $success = parent::verifyWithKeySet($jws, $jwkset, $signatureIndex, $detachedPayload, $jwk);
        if ($success) {
            $this->eventDispatcher->dispatch(new JWSVerificationSuccessEvent($jws, $jwkset, $signatureIndex, $detachedPayload, $jwk));
        } else {
            $this->eventDispatcher->dispatch(new JWSVerificationFailureEvent($jws, $jwkset, $detachedPayload));
        }
        return $success;
    }
}