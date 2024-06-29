<?php
declare(strict_types=1);

namespace CT\AmpPool\WorkerPoolMocks;

use CT\AmpPool\Exceptions\TerminateWorkerException;
use CT\AmpPool\Worker\WorkerEntryPointInterface;
use CT\AmpPool\Worker\WorkerInterface;

final class TerminateWorkerEntryPoint implements WorkerEntryPointInterface
{
    public function initialize(WorkerInterface $worker): void
    {
    }

    public function run(): void
    {
        throw new TerminateWorkerException();
    }
}
