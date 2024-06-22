<?php
declare(strict_types=1);

namespace CT\AmpPool\Strategies\PickupStrategy;

use CT\AmpPool\Strategies\WorkerStrategyAbstract;

abstract class PickupStrategyAbstract       extends WorkerStrategyAbstract
                                            implements PickupStrategyInterface
{
    protected function iterate(array $possibleGroups = [], array $possibleWorkers = [], array $ignoredWorkers = []): iterable
    {
        $groupsState                = $this->getPoolStateStorage()->getGroupsState();
        $currentWorkerId            = $this->getCurrentWorkerId();
        
        foreach ($groupsState as $groupId => [$lowestWorkerId, $highestWorkerId]) {
            
            if($possibleGroups !== [] && false === in_array($groupId, $possibleGroups)) {
                continue;
            }
            
            foreach (range($lowestWorkerId, $highestWorkerId) as $workerId) {
                
                if($workerId === $currentWorkerId) {
                    continue;
                }
                
                if($possibleWorkers !== [] && false === in_array($workerId, $possibleWorkers, true)) {
                    continue;
                }
                
                if($ignoredWorkers !== [] && in_array($workerId, $ignoredWorkers, true)) {
                    continue;
                }

                yield $workerId;
            }
        }
    }
}