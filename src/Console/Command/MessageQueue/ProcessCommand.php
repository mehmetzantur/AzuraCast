<?php

namespace App\Console\Command\MessageQueue;

use App\Console\Command\CommandAbstract;
use App\Doctrine\Messenger\ClearEntityManagerSubscriber;
use App\EventDispatcher;
use App\MessageQueue\LogWorkerExceptionSubscriber;
use App\MessageQueue\QueueManager;
use App\MessageQueue\ReloadSettingsMiddleware;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\EventListener\StopWorkerOnTimeLimitListener;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Worker;

class ProcessCommand extends CommandAbstract
{
    public function __invoke(
        MessageBus $messageBus,
        EventDispatcher $eventDispatcher,
        QueueManager $queueManager,
        LoggerInterface $logger,
        int $runtime = 0,
        ?string $workerName = null
    ): int {
        $logger->notice(
            'Starting new Message Queue worker process.',
            [
                'runtime' => $runtime,
                'workerName' => $workerName,
            ]
        );

        if (null !== $workerName) {
            $queueManager->setWorkerName($workerName);
        }

        $receivers = $queueManager->getTransports();

        $eventDispatcher->addServiceSubscriber(ClearEntityManagerSubscriber::class);
        $eventDispatcher->addServiceSubscriber(LogWorkerExceptionSubscriber::class);
        $eventDispatcher->addServiceSubscriber(ReloadSettingsMiddleware::class);

        if ($runtime > 0) {
            $eventDispatcher->addSubscriber(new StopWorkerOnTimeLimitListener($runtime, $logger));
        }

        try {
            $worker = new Worker($receivers, $messageBus, $eventDispatcher, $logger);
            $worker->run();
        } catch (\Throwable $e) {
            $logger->error(
                sprintf('Message queue error: %s', $e->getMessage()),
                [
                    'workerName' => $workerName,
                    'exception' => $e,
                ]
            );
            return 1;
        }


        return 0;
    }
}
