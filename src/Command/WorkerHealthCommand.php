<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:worker:health',
    description: 'Check the health of the async worker infrastructure (Redis, queues).',
)]
final class WorkerHealthCommand extends Command
{
    public function __construct(
        private readonly string $messengerTransportDsn,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->section('Worker Health Check');

        // Parse Redis DSN
        $parsed = parse_url($this->messengerTransportDsn);
        if (!$parsed || $parsed['scheme'] !== 'redis') {
            $io->error('Invalid MESSENGER_TRANSPORT_DSN. Expected redis:// DSN.');
            return Command::FAILURE;
        }

        $host = $parsed['host'] ?? 'localhost';
        $port = $parsed['port'] ?? 6379;
        $path = $parsed['path'] ?? '/0';
        $db = (int) trim($path, '/');

        $io->writeln([
            "Redis DSN: redis://$host:$port/$db",
        ]);

        // Check Redis connectivity
        $io->writeln('');
        $io->writeln('Checking Redis connectivity...');

        try {
            $redis = new \Redis();
            $connected = @$redis->connect($host, $port, 2.0);

            if (!$connected) {
                $io->error("Failed to connect to Redis at $host:$port");
                return Command::FAILURE;
            }

            $redis->select($db);
            $pong = $redis->ping();

            if ($pong !== true && $pong !== 'PONG') {
                $io->error('Redis PING failed.');
                return Command::FAILURE;
            }

            $io->success('Redis connectivity OK.');

            // Check queue depth
            $io->writeln('');
            $io->writeln('Checking queue depth...');

            $queueSize = $redis->llen('messages');
            $io->writeln("Messages in queue: <info>$queueSize</info>");

            // Check Redis memory
            $info = $redis->info('memory');
            if ($info && isset($info['used_memory_human'])) {
                $io->writeln("Redis memory usage: <info>{$info['used_memory_human']}</info>");
            }

            $redis->close();

            $io->writeln('');
            $io->success('Worker infrastructure is healthy.');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error("Worker health check failed: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}
