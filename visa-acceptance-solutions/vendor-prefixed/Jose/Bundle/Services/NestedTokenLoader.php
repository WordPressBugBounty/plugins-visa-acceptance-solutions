<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Services;

use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Event\NestedTokenLoadingFailureEvent;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Event\NestedTokenLoadingSuccessEvent;
use Pymt_Vas\Dependencies\Jose\Component\Core\JWKSet;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\JWELoader;
use Pymt_Vas\Dependencies\Jose\Component\NestedToken\NestedTokenLoader as BaseNestedTokenLoader;
use Pymt_Vas\Dependencies\Jose\Component\Signature\JWS;
use Pymt_Vas\Dependencies\Jose\Component\Signature\JWSLoader;
use Pymt_Vas\Dependencies\Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;
final class NestedTokenLoader extends BaseNestedTokenLoader
{
    public function __construct(JWELoader $jweLoader, JWSLoader $jwsLoader, private readonly EventDispatcherInterface $eventDispatcher)
    {
        parent::__construct($jweLoader, $jwsLoader);
    }
    public function load(string $token, JWKSet $encryptionKeySet, JWKSet $signatureKeySet, ?int &$signature = null): JWS
    {
        try {
            $jws = parent::load($token, $encryptionKeySet, $signatureKeySet, $signature);
            $this->eventDispatcher->dispatch(new NestedTokenLoadingSuccessEvent($token, $jws, $signatureKeySet, $encryptionKeySet, $signature));
            return $jws;
        } catch (Throwable $throwable) {
            $this->eventDispatcher->dispatch(new NestedTokenLoadingFailureEvent($token, $signatureKeySet, $encryptionKeySet, $throwable));
            throw $throwable;
        }
    }
}