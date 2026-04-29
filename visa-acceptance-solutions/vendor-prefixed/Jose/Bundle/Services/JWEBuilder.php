<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Services;

use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Event\JWEBuiltFailureEvent;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Event\JWEBuiltSuccessEvent;
use Pymt_Vas\Dependencies\Jose\Component\Core\AlgorithmManager;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\Compression\CompressionMethodManager;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\JWE;
use Pymt_Vas\Dependencies\Jose\Component\Encryption\JWEBuilder as BaseJWEBuilder;
use Pymt_Vas\Dependencies\Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;
final class JWEBuilder extends BaseJWEBuilder
{
    public function __construct(AlgorithmManager $algorithmManager, null|AlgorithmManager $contentEncryptionAlgorithmManager, null|CompressionMethodManager $compressionManager, private readonly EventDispatcherInterface $eventDispatcher)
    {
        parent::__construct($algorithmManager, $contentEncryptionAlgorithmManager, $compressionManager);
    }
    public function build(): JWE
    {
        try {
            $jwe = parent::build();
            $this->eventDispatcher->dispatch(new JWEBuiltSuccessEvent($jwe));
            return $jwe;
        } catch (Throwable $throwable) {
            $this->eventDispatcher->dispatch(new JWEBuiltFailureEvent($this->payload, $this->recipients, $this->sharedProtectedHeader, $this->sharedHeader, $this->aad, $throwable));
            throw $throwable;
        }
    }
}