<?php
declare(strict_types=1);

namespace CT\AmpServer;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Cluster\ClusterException;
use Amp\Cluster\ClusterWorkerMessage;
use Amp\CompositeException;
use Amp\DeferredCancellation;
use Amp\Future;
use Amp\Parallel\Context\ContextException;
use Amp\Parallel\Context\ContextFactory;
use Amp\Parallel\Context\DefaultContextFactory;
use Amp\Parallel\Context\ProcessContext;
use Amp\Parallel\Ipc\IpcHub;
use Amp\Parallel\Ipc\LocalIpcHub;
use Amp\Pipeline\ConcurrentIterator;
use Amp\Pipeline\Queue;
use Amp\Socket\ResourceSocket;
use Amp\Socket\ServerSocket;
use Amp\Sync\ChannelException;
use CT\AmpServer\Messages\MessageSocketTransfer;
use CT\AmpServer\SocketPipe\SocketListener;
use CT\AmpServer\SocketPipe\SocketListenerProvider;
use CT\AmpServer\SocketPipe\SocketPipeProvider;
use Psr\Log\LoggerInterface as PsrLogger;
use Revolt\EventLoop;
use function Amp\async;

/**
 * Worker Pool Manager Class.
 *
 * A worker pool allows you to create groups of processes belonging to different types of workers,
 * and then use them to perform tasks.
 *
 * @template-covariant TReceive
 * @template TSend
 */
class WorkerPool                    implements WorkerPoolI
{
    protected int $workerStartTimeout = 5;
    
    /**
     * @var WorkerDescriptor[]
     */
    protected array $workers        = [];
    
    /** @var array<int, Future<void>> */
    protected array $workerFutures  = [];
    
    /** @var Queue<ClusterWorkerMessage<TReceive, TSend>> */
    protected readonly Queue $queue;
    /** @var ConcurrentIterator<ClusterWorkerMessage<TReceive, TSend>> */
    private readonly ConcurrentIterator $iterator;
    private bool $running = false;
    private SocketPipeProvider $provider;
    
    private ?SocketListenerProvider $listenerProvider = null;
    
    public function __construct(
        public readonly int $reactorCount,
        public readonly int $jobCount,
        protected readonly IpcHub $hub      = new LocalIpcHub(),
        protected ?ContextFactory $contextFactory = null,
        protected string|array $script      = '',
        protected ?PsrLogger $logger        = null
    ) {
        $this->script               = \array_merge(
            [__DIR__ . '/runner.php'],
            \is_array($script) ? \array_values(\array_map(\strval(...), $script)) : [$script],
        );
        
        $this->provider             = new SocketPipeProvider($this->hub);
        $this->contextFactory       ??= new DefaultContextFactory(ipcHub: $this->hub);
        $this->queue                = new Queue();
        $this->iterator             = $this->queue->iterate();
        
        // For Windows, we should use the SocketListenerProvider instead of the SocketPipeProvider
        if(PHP_OS_FAMILY === 'Windows') {
            $this->listenerProvider = new SocketListenerProvider($this);
        }
    }
    
    public function run(): void
    {
        if ($this->running || $this->queue->isComplete()) {
            throw new \Error('The cluster watcher is already running or has already run');
        }
        
        if (count($this->workers) <= 0) {
            throw new \Error('The number of workers must be greater than zero');
        }
        
        $this->running              = true;
        
        try {
            foreach ($this->workers as $worker) {
                $this->startWorker($worker);
            }
        } catch (\Throwable $exception) {
            $this->stop();
            throw $exception;
        }
    }
    
    public function getMessageIterator(): iterable
    {
        return $this->iterator;
    }
    
    public function mainLoop(): void
    {
        foreach ($this->getMessageIterator() as $message) {
            continue;
        }
    }
    
    private function startWorker(WorkerDescriptor $workerDescriptor): void
    {
        $context                    = $this->contextFactory->start($this->script);
        $key                        = $this->hub->generateKey();
        
        $context->send([
            'id'                    => $workerDescriptor->id,
            'uri'                   => $this->hub->getUri(),
            'key'                   => $key,
            'type'                  => $workerDescriptor->type->value,
            'entryPoint'            => $workerDescriptor->entryPointClassName,
        ]);
        
        try {
            $socketTransport        = $this->provider->createSocketTransport($key);
        } catch (\Throwable $exception) {
            if (!$context->isClosed()) {
                $context->close();
            }
            
            throw new \Exception("Starting the worker '{$workerDescriptor->id}' failed. Socket provider start failed", previous: $exception);
        }
        
        $deferredCancellation       = new DeferredCancellation();
        
        $worker                     = new WorkerProcessContext(
            $workerDescriptor->id,
            $context,
            $socketTransport ?? $this->listenerProvider,
            $this->queue,
            $deferredCancellation
        );
        
        if($this->logger !== null) {
            $worker->setLogger($this->logger);
        }
        
        $workerDescriptor->setWorker($worker);
        
        $worker->info(\sprintf('Started %s worker #%d', $workerDescriptor->type->value, $workerDescriptor->id));
        
        // Server stopped while worker was starting, so immediately throw everything away.
        if (false === $this->running) {
            $worker->shutdown();
            return;
        }
        
        $workerDescriptor->setFuture(async(function () use (
            $worker,
            $context,
            $socketTransport,
            $deferredCancellation,
            $workerDescriptor
        ): void {
            async($this->provider->provideFor(...), $socketTransport, $deferredCancellation->getCancellation())->ignore();
            
            $id                         = $workerDescriptor->id;
            
            try {
                try {
                    $worker->runWorkerLoop();
                    
                    $worker->info("Worker {$id} terminated cleanly" .
                                  ($this->running ? ", restarting..." : ""));
                } catch (CancelledException) {
                    $worker->info("Worker {$id} forcefully terminated as part of watcher shutdown");
                } catch (ChannelException $exception) {
                    $worker->error("Worker {$id} died unexpectedly: {$exception->getMessage()}" .
                                   ($this->running ? ", restarting..." : ""));
                } catch (\Throwable $exception) {
                    $worker->error(
                        "Worker {$id} failed: " . (string) $exception,
                        ['exception' => $exception],
                    );
                    throw $exception;
                } finally {
                    $deferredCancellation->cancel();
                    $workerDescriptor->reset();
                    $context->close();
                }
                
                if ($this->running) {
                    $this->startWorker($workerDescriptor);
                }
            } catch (\Throwable $exception) {
                $this->stop();
                throw $exception;
            }
        })->ignore());
    }
    
    public function fillWorkersWith(string $workerClass): void
    {
        $index                      = 1;
        
        if($this->reactorCount > 0) {
            foreach (range($index, $this->reactorCount) as $id) {
                $this->addWorker(new WorkerDescriptor($id, WorkerTypeEnum::REACTOR, $workerClass));
            }
        }
        
        $index                      = $this->reactorCount + 1;
        
        if($this->jobCount > 0) {
            foreach (range($index, $this->jobCount + 1) as $id) {
                $this->addWorker(new WorkerDescriptor($id, WorkerTypeEnum::JOB, $workerClass));
            }
        }
    }
    
    public function addWorker(WorkerDescriptor $worker): void
    {
        $this->workers[]            = $worker;
    }
    
    public function getWorkers(): array
    {
        return $this->workers;
    }
    
    public function pickupWorker(WorkerTypeEnum $workerType = null, array $possibleWorkers = null): ?WorkerDescriptor
    {
        foreach ($this->workers as $workerDescriptor) {
            
            if ($possibleWorkers !== null && !\in_array($workerDescriptor->id, $possibleWorkers, true)) {
                continue;
            }
            
            if ($workerDescriptor->getWorker()?->isReady()) {
                return $workerDescriptor;
            }
        }
        
        return null;
    }
    
    /**
     * Stops all cluster workers. Workers are killed if the cancellation token is cancelled.
     *
     * @param Cancellation|null $cancellation Token to request cancellation of waiting for shutdown.
     * When cancelled, the workers are forcefully killed. If null, the workers are killed immediately.
     */
    public function stop(?Cancellation $cancellation = null): void
    {
        if ($this->queue->isComplete()) {
            return;
        }
        
        $this->running              = false;
        $this->listenerProvider?->close();
        
        $futures                    = [];
        
        foreach ($this->workers as $workerDescriptor) {
            $futures[]              = async(static function () use ($workerDescriptor, $cancellation): void {
                $future             = $workerDescriptor->getFuture();
                
                try {
                    $workerDescriptor->getWorker()?->shutdown($cancellation);
                } catch (ContextException) {
                    // Ignore if the worker has already died unexpectedly.
                }
                
                // We need to await this future here, otherwise we may not log things properly if the
                // event-loop exits immediately after.
                $future?->await();
            });
        }
        
        [$exceptions]               = Future\awaitAll($futures);
        
        try {
            if (!$exceptions) {
                $this->queue->complete();
                return;
            }
            
            if (\count($exceptions) === 1) {
                $exception          = \array_shift($exceptions);
                $this->queue->error(new ClusterException(
                    "Stopping the cluster failed: " . $exception->getMessage(),
                    previous: $exception,
                ));
                
                return;
            }
            
            $exception              = new CompositeException($exceptions);
            $message                = \implode('; ', \array_map(static fn (\Throwable $e) => $e->getMessage(), $exceptions));
            
            $this->queue->error(new ClusterException("Stopping the cluster failed: " . $message, previous: $exception));
        } finally {
            $this->workers          = [];
        }
    }
    
    public function __destruct()
    {
        EventLoop::queue($this->stop(...));
    }
}