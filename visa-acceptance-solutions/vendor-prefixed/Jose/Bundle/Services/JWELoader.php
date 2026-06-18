<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Services;

use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Event\JWELoadingFailureEvent;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Event\JWELoadingSuccessEvent;
use Pymt_Vas\Dependencies\Jose\Component\Checker\HeaderCheckerManager;
use Pymt_Vas\Dependencies\Jose\Component\Core\JWKSet;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\JWE;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\JWEDecrypter;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\JWELoader as BaseJWELoader;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\Serializer\JWESerializerManager;
use Pymt_Vas\Dependencies\Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;
final class JWELoader extends BaseJWELoader
{
    public function __construct(JWESerializerManager $serializerManager, JWEDecrypter $jweDecrypter, ?HeaderCheckerManager $headerCheckerManager, private readonly EventDispatcherInterface $eventDispatcher)
    {
        parent::__construct($serializerManager, $jweDecrypter, $headerCheckerManager);
    }
    public function loadAndDecryptWithKeySet(string $token, JWKSet $keyset, ?int &$recipient): JWE
    {
        try {
            $jwe = parent::loadAndDecryptWithKeySet($token, $keyset, $recipient);
            $this->eventDispatcher->dispatch(new JWELoadingSuccessEvent($token, $jwe, $keyset, $recipient));
            return $jwe;
        } catch (Throwable $throwable) {
            $this->eventDispatcher->dispatch(new JWELoadingFailureEvent($token, $keyset, $throwable));
            throw $throwable;
        }
    }
}