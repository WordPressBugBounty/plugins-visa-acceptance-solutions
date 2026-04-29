<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Services;

use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Event\ClaimCheckedFailureEvent;
use Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\Event\ClaimCheckedSuccessEvent;
use Pymt_Vas\Dependencies\Jose\Component\Checker\ClaimCheckerManager as BaseClaimCheckerManager;
use Pymt_Vas\Dependencies\Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;
final class ClaimCheckerManager extends BaseClaimCheckerManager
{
    public function __construct($checkers, private readonly EventDispatcherInterface $eventDispatcher)
    {
        parent::__construct($checkers);
    }
    public function check(array $claims, array $mandatoryClaims = []): array
    {
        try {
            $checkedClaims = BaseClaimCheckerManager::check($claims, $mandatoryClaims);
            $this->eventDispatcher->dispatch(new ClaimCheckedSuccessEvent($claims, $mandatoryClaims, $checkedClaims));
            return $checkedClaims;
        } catch (Throwable $throwable) {
            $this->eventDispatcher->dispatch(new ClaimCheckedFailureEvent($claims, $mandatoryClaims, $throwable));
            throw $throwable;
        }
    }
}