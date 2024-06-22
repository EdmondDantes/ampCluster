<?php
declare(strict_types=1);

namespace CT\AmpPool\Worker;

use Amp\Cancellation;
use Amp\Cluster\ServerSocketPipeFactory;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\Parallel\Ipc;
use Amp\Pipeline\ConcurrentIterator;
use Amp\Pipeline\Queue;
use Amp\Socket\ResourceSocket;
use Amp\Socket\ServerSocketFactory;
use Amp\Sync\Channel;
use Amp\TimeoutCancellation;
use CT\AmpPool\Internal\Messages\MessagePingPong;
use CT\AmpPool\Internal\Messages\MessageShutdown;
use CT\AmpPool\Internal\SocketPipe\SocketPipeFactoryWindows;
use CT\AmpPool\JobIpc\IpcServer;
use CT\AmpPool\JobIpc\JobRequest;
use CT\AmpPool\PoolState\PoolStateReadableInterface;
use CT\AmpPool\PoolState\PoolStateStorage;
use CT\AmpPool\Strategies\JobRunner\JobRunnerInterface;
use CT\AmpPool\Worker\Internal\WorkerLogHandler;
use CT\AmpPool\Worker\WorkerState\WorkersInfo;
use CT\AmpPool\Worker\WorkerState\WorkersInfoInterface;
use CT\AmpPool\Worker\WorkerState\WorkerStateStorage;
use CT\AmpPool\Worker\WorkerState\WorkerStateStorageInterface;
use CT\AmpPool\WorkerEventEmitter;
use CT\AmpPool\WorkerEventEmitterInterface;
use CT\AmpPool\WorkerGroup;
use CT\AmpPool\WorkerTypeEnum;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use function Amp\delay;

/**
 * Abstraction of Worker Representation within the worker process.
 * This class should not be used within the process that creates workers!
 *
 * @template-covariant TReceive
 * @template TSend
 * @implements Channel<TReceive, TSend>
 */
class Worker                        implements WorkerInterface
{
    protected int $timeout = 5;
    
    protected readonly DeferredCancellation $loopCancellation;
    
    /** @var Queue<TReceive> */
    protected readonly Queue $queue;
    
    /** @var ConcurrentIterator<TReceive> */
    protected readonly ConcurrentIterator $iterator;
    
    protected ?ResourceSocket $ipcForTransferSocket = null;
    protected ?ServerSocketFactory $socketPipeFactory = null;
    
    private LoggerInterface $logger;
    private IpcServer|null          $jobIpc      = null;
    private JobRunnerInterface|null $jobHandler  = null;
    private WorkerStateStorage|null $workerState = null;
    private PoolStateReadableInterface $poolState;
    private WorkerStateStorageInterface $workerStateStorage;
    private WorkersInfoInterface $workersInfo;
    private WorkerEventEmitterInterface $eventEmitter;
    
    public function __construct(
        private readonly int     $id,
        private readonly Channel $ipcChannel,
        private readonly string  $key,
        private readonly string  $uri,
        private readonly WorkerGroup $group,
        /**
         * @var array<int, WorkerGroup>
         */
        private readonly array $groupsScheme,
        LoggerInterface          $logger = null
    ) {
        $this->queue                = new Queue();
        $this->iterator             = $this->queue->iterate();
        $this->loopCancellation     = new DeferredCancellation();
        
        if($this->group->getWorkerType() === WorkerTypeEnum::JOB) {
            $this->jobIpc           = new IpcServer($this->id);
        }
        
        $this->poolState            = new PoolStateStorage;
        $this->workerStateStorage   = new WorkerStateStorage($this->id, $this->group->getWorkerGroupId(), true);
        $this->workersInfo          = new WorkersInfo;
        $this->eventEmitter         = new WorkerEventEmitter;
        
        if($logger !== null) {
            $this->logger           = $logger;
        } else {
            $this->logger           = new \Monolog\Logger('worker-'.$id);
            $this->logger->pushHandler(new WorkerLogHandler($this->ipcChannel));
        }
    }
    
    public function initWorker(): void
    {
        $this->getSocketPipeFactory();
    }

    public function sendMessageToWatcher(mixed $message): void
    {
        $this->ipcChannel->send($message);
    }
    
    /**
     * @return array<int, WorkerGroup>
     */
    public function getGroupsScheme(): array
    {
        return $this->groupsScheme;
    }
    
    public function getPoolStateStorage(): PoolStateReadableInterface
    {
        return $this->poolState;
    }
    
    public function getWorkerStateStorage(): WorkerStateStorageInterface
    {
        return $this->workerStateStorage;
    }
    
    public function getWorkersInfo(): WorkersInfoInterface
    {
        return $this->workersInfo;
    }
    
    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }
    
    public function getWorkerId(): int
    {
        return $this->id;
    }
    
    public function getWorkerGroup(): WorkerGroup
    {
        return $this->group;
    }
    
    public function getWorkerGroupId(): int
    {
        return $this->group->getWorkerGroupId();
    }
    
    public function getWorkerType(): WorkerTypeEnum
    {
        return $this->group->getWorkerType();
    }
    
    public function getIpcForTransferSocket(): ResourceSocket
    {
        if($this->ipcForTransferSocket !== null) {
            return $this->ipcForTransferSocket;
        }
        
        try {
            $socket                 = Ipc\connect($this->uri, $this->key, new TimeoutCancellation($this->timeout));
            
            if($socket instanceof ResourceSocket) {
                $this->ipcForTransferSocket = $socket;
            } else {
                throw new \RuntimeException('Type of socket is not ResourceSocket');
            }
            
        } catch (\Throwable $exception) {
            throw new \RuntimeException('Could not connect to IPC socket', 0, $exception);
        }
        
        return $this->ipcForTransferSocket;
    }
    
    public function getWorkerEventEmitter(): WorkerEventEmitterInterface
    {
        return $this->eventEmitter;
    }
    
    public function getSocketPipeFactory(): ServerSocketFactory
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return new SocketPipeFactoryWindows($this->ipcChannel, $this);
        }
        
        if($this->socketPipeFactory !== null) {
            return $this->socketPipeFactory;
        }
        
        $this->socketPipeFactory    = new ServerSocketPipeFactory($this->getIpcForTransferSocket());
        
        return $this->socketPipeFactory;
    }
    
    public function getJobHandler(): JobRunnerInterface|null
    {
        return $this->jobHandler;
    }
    
    public function setJobHandler(JobRunnerInterface $jobHandler): self
    {
        $this->jobHandler           = $jobHandler;
        
        return $this;
    }
    
    public function mainLoop(): void
    {
        $abortCancellation          = $this->loopCancellation->getCancellation();
        
        if($this->group->getJobRunner() !== null) {
            EventLoop::queue($this->jobLoop(...), $abortCancellation);
        }
        
        try {
            while ($message = $this->ipcChannel->receive($abortCancellation)) {
                
                if($message instanceof MessagePingPong) {
                    $this->ipcChannel->send(new MessagePingPong);
                    continue;
                }
                
                if($message instanceof MessageShutdown) {
                    $this->logger->info('Received shutdown message');
                    break;
                }
                
                $this->eventEmitter->emitWorkerEvent($message);
            }
        } catch (\Throwable) {
            // IPC Channel manually closed
        } finally {
            $this->jobHandler       = null;
            $this->eventEmitter->free();
            
            if(false === $this->loopCancellation->isCancelled()) {
                $this->loopCancellation->cancel();
            }
            
            $this->queue->complete();
            $this->ipcForTransferSocket?->close();
        }
    }
    
    protected function jobLoop(Cancellation $cancellation = null): void
    {
        if(null === $this->group->getJobRunner()) {
            return;
        }
        
        $jobRunner                  = $this->group->getJobRunner();
        
        $this->workerState          = new WorkerStateStorage($this->id, $this->group->getWorkerGroupId(), true);
        $this->workerState->workerReady();
        
        $jobQueueIterator           = $this->jobIpc->getJobQueue()->iterate();
        $selfRef                    = \WeakReference::create($this);
        
        while ($jobQueueIterator->continue($cancellation)) {
            
            [$channel, $jobRequest] = $jobQueueIterator->getValue();
            
            if($jobRequest === null) {
                continue;
            }
            
            $this->workerState->incrementJobCount();
            
            $jobRunner->runJob($jobRequest->data, $jobRequest->priority, $cancellation)
                      ->finally(static function (mixed $result) use ($channel, $jobRequest, $selfRef, $cancellation) {
                          
                          $selfRef->get()?->workerState->decrementJobCount();
                          $selfRef->get()?->jobIpc?->sendJobResult($result, $channel, $jobRequest, $cancellation);
                      });
        }
    }
    
    public function addEventHandler(callable $handler): self
    {
        $this->messageHandlers[]    = $handler;
        
        return $this;
    }
    
    public function awaitTermination(?Cancellation $cancellation = null): void
    {
        $deferredFuture             = new DeferredFuture();
        $loopCancellation           = $this->loopCancellation->getCancellation();
        
        $loopId                     = $loopCancellation->subscribe($deferredFuture->complete(...));
        $cancellationId             = $cancellation?->subscribe(static fn () => $loopCancellation->unsubscribe($loopId));
        
        try {
            $deferredFuture->getFuture()->await($cancellation);
        } finally {
            /** @psalm-suppress PossiblyNullArgument $cancellationId is not null if $cancellation is not null. */
            $cancellation?->unsubscribe($cancellationId);
        }
    }
    
    public function __destruct()
    {
        EventLoop::queue($this->stop(...));
    }
    
    public function stop(): void
    {
        if($this->loopCancellation->isCancelled()) {
            return;
        }
        
        $this->loopCancellation->cancel();
    }
    
    public function isStopped(): bool
    {
        return $this->loopCancellation->isCancelled();
    }
    
    public function onClose(\Closure $onClose): void
    {
        $this->loopCancellation->getCancellation()->subscribe(static fn () => $onClose());
    }
    
    public function __toString(): string
    {
        return $this->group->getGroupName().'-'.$this->id;
    }
}