<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Services;

use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Event\JWEDecryptionFailureEvent;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Event\JWEDecryptionSuccessEvent;
use Pymt_Vas\Dependencies\Jose\Component\Core\AlgorithmManager;
use Pymt_Vas\Dependencies\Jose\Component\Core\JWK;
use Pymt_Vas\Dependencies\Jose\Component\Core\JWKSet;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\Compression\CompressionMethodManager;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\JWE;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\JWEDecrypter as BaseJWEDecrypter;
use Pymt_Vas\Dependencies\Psr\EventDispatcher\EventDispatcherInterface;
final class JWEDecrypter extends BaseJWEDecrypter
{
    public function __construct(AlgorithmManager $algorithmManager, null|AlgorithmManager $contentEncryptionAlgorithmManager, null|CompressionMethodManager $compressionMethodManager, private readonly EventDispatcherInterface $eventDispatcher)
    {
        parent::__construct($algorithmManager, $contentEncryptionAlgorithmManager, $compressionMethodManager);
    }
    public function decryptUsingKeySet(JWE &$jwe, JWKSet $jwkset, int $recipient, ?JWK &$jwk = null, ?JWK $senderKey = null): bool
    {
        $success = parent::decryptUsingKeySet($jwe, $jwkset, $recipient, $jwk, $senderKey);
        if ($success) {
            $this->eventDispatcher->dispatch(new JWEDecryptionSuccessEvent($jwe, $jwkset, $jwk, $recipient));
        } else {
            $this->eventDispatcher->dispatch(new JWEDecryptionFailureEvent($jwe, $jwkset));
        }
        return $success;
    }
}