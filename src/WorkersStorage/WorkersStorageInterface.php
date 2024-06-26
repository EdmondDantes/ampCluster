<?php
declare(strict_types=1);

namespace CT\AmpPool\WorkersStorage;

interface WorkersStorageInterface
{
    public static function instanciate(int $workersCount = 0): static;

    public function getWorkerState(int $workerId): WorkerStateInterface;

    public function reviewWorkerState(int $workerId): WorkerStateInterface;

    /**
     * @return WorkerStateInterface[]
     */
    public function foreachWorkers(): array;

    public function readWorkerState(int $workerId, int $offset = 0): string;

    public function updateWorkerState(int $workerId, string $data, int $offset = 0): void;

    public function close(): void;
}
