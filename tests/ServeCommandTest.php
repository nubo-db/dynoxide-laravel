<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests;

use Aws\DynamoDb\DynamoDbClient;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Symfony\Component\Process\Process;

/**
 * Runs `dynoxide:serve` as a real artisan process, because signal handlers
 * set with trap() do nothing inside PHPUnit. The server binary comes from
 * DYNOXIDE_BINARY when it's set, as it would for an app. The tests send
 * POSIX signals, so they're in the posix group, which CI leaves out on Windows.
 */
#[Group('posix')]
final class ServeCommandTest extends PHPUnitTestCase
{
    private const READY = 'Press Ctrl+C to stop';

    private string $dir;

    /** @var list<Process> */
    private array $commands = [];

    /** @var list<int> */
    private array $serverPids = [];

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('posix_kill')) {
            $this->markTestSkipped('These tests send POSIX signals.');
        }

        $this->dir = sys_get_temp_dir() . '/dynoxide-serve-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/tmp', 0700, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->commands as $command) {
            if ($command->isRunning()) {
                $command->stop(5);
            }
        }
        // A server that outlived its command is a failure the test reports;
        // it still mustn't be left running.
        foreach ($this->serverPids as $pid) {
            if (self::alive($pid)) {
                posix_kill($pid, SIGKILL);
            }
        }
        if (isset($this->dir)) {
            self::removeDir($this->dir);
        }
    }

    public function test_it_prints_the_ready_line_and_the_apps_tables_exist(): void
    {
        $port = self::freePort();

        $run = $this->serve([], [
            'DYNOXIDE_PORT' => (string) $port,
            'DYNAMODB_CACHE_TABLE' => 'serve_cache',
            'QUEUE_FAILED_DRIVER' => 'dynamodb',
        ]);

        $this->assertStringContainsString("Dynoxide running on [http://127.0.0.1:{$port}].", $run['output']);
        $this->assertStringContainsString(self::READY, $run['output']);
        $this->assertSame(['failed_jobs', 'serve_cache'], self::tableNames($port));

        $failed = self::client($port)->describeTable(['TableName' => 'failed_jobs'])['Table']['KeySchema'];
        $this->assertSame(
            [['AttributeName' => 'application', 'KeyType' => 'HASH'], ['AttributeName' => 'uuid', 'KeyType' => 'RANGE']],
            $failed,
        );
    }

    public function test_the_server_keeps_answering_after_it_is_ready(): void
    {
        $port = self::freePort();
        $run = $this->serve(['--port' => $port]);

        sleep(5);

        $this->assertTrue($run['process']->isRunning(), 'The command stopped: ' . $run['process']->getOutput() . $run['process']->getErrorOutput());
        $this->assertTrue(self::alive($run['serverPid']), 'The server stopped.');
        $this->assertTrue(self::answersGet($port), 'GET / stopped answering.');
    }

    public function test_the_schema_file_is_gone_once_the_server_is_ready(): void
    {
        $port = self::freePort();
        $this->serve(['--port' => $port], ['DYNAMODB_CACHE_TABLE' => 'schema_cache']);

        $this->assertSame(['schema_cache'], self::tableNames($port));
        $this->assertSame([], self::filesIn($this->dir . '/tmp'));
    }

    public function test_sigterm_stops_the_server_and_exits_zero(): void
    {
        $port = self::freePort();
        $run = $this->serve(['--port' => $port]);

        posix_kill($run['process']->getPid(), SIGTERM);
        $status = $run['process']->wait();

        $this->assertSame(0, $status, $run['process']->getOutput() . $run['process']->getErrorOutput());
        $this->assertTrue(self::waitUntil(fn (): bool => !self::alive($run['serverPid']), 5.0), 'The server is still running.');
        $this->assertTrue(self::portIsFree($port), "Port {$port} is still in use.");
    }

    public function test_a_killed_command_takes_the_server_with_it(): void
    {
        $port = self::freePort();
        $run = $this->serve(['--port' => $port]);

        posix_kill($run['process']->getPid(), SIGKILL);
        $this->assertTrue(self::waitUntil(fn (): bool => !$run['process']->isRunning(), 5.0));

        $this->assertTrue(self::waitUntil(fn (): bool => !self::alive($run['serverPid']), 5.0), 'The server outlived the killed command.');
        $this->assertTrue(self::portIsFree($port), "Port {$port} is still in use.");
    }

    public function test_a_port_in_use_fails_with_dynoxides_message(): void
    {
        $port = self::freePort();
        $holder = stream_socket_server("tcp://127.0.0.1:{$port}");
        $this->assertNotFalse($holder);

        try {
            $process = $this->command(['--port' => $port]);
            $process->start();
            $status = $process->wait();
        } finally {
            fclose($holder);
        }

        // Dynoxide's own status, passed through.
        $this->assertSame(1, $status);
        $this->assertStringContainsString("port {$port} is already in use", $process->getOutput() . $process->getErrorOutput());
        $this->assertStringNotContainsString('Dynoxide running on', $process->getOutput());
    }

    public function test_no_tables_starts_without_the_apps_tables(): void
    {
        $port = self::freePort();
        $this->serve(['--port' => $port, '--no-tables' => true], ['QUEUE_FAILED_DRIVER' => 'dynamodb']);

        $this->assertSame([], self::tableNames($port));
    }

    public function test_a_db_path_keeps_data_across_a_restart(): void
    {
        $port = self::freePort();
        $dbPath = $this->dir . '/serve.db';

        $first = $this->serve(['--port' => $port, '--db-path' => $dbPath]);
        self::client($port)->putItem(['TableName' => 'cache', 'Item' => ['key' => ['S' => 'kept'], 'value' => ['S' => 'yes']]]);
        posix_kill($first['process']->getPid(), SIGTERM);
        $this->assertSame(0, $first['process']->wait());

        $this->serve(['--port' => $port, '--db-path' => $dbPath]);
        $item = self::client($port)->getItem(['TableName' => 'cache', 'Key' => ['key' => ['S' => 'kept']]])['Item'] ?? null;

        $this->assertSame(['S' => 'yes'], $item['value'] ?? null);
    }

    public function test_a_binary_without_the_launcher_flags_still_starts_and_warns_once(): void
    {
        $legacy = self::legacyBinary();
        if ($legacy === null) {
            $this->markTestSkipped('No Dynoxide 1.6.0 binary: set DYNOXIDE_LEGACY_BINARY, or cache one under ~/.cache/dynoxide/1.6.0.');
        }
        $port = self::freePort();

        $run = $this->serve(['--port' => $port], ['DYNOXIDE_BINARY' => $legacy, 'DYNAMODB_CACHE_TABLE' => 'legacy_cache']);

        // The ready line is printed only after GET / answers, so it must answer now.
        $this->assertTrue(self::answersGet($port, 0.2));
        $this->assertStringContainsString("Dynoxide running on [http://127.0.0.1:{$port}].", $run['output']);
        $this->assertSame(1, substr_count($run['output'], 'Dynoxide 1.6.0 '));
        $this->assertStringContainsString('if this command is killed', $run['output']);
        $this->assertSame(['legacy_cache'], self::tableNames($port));
        $this->assertSame([], self::filesIn($this->dir . '/tmp'));

        posix_kill($run['process']->getPid(), SIGTERM);
        $this->assertSame(0, $run['process']->wait());
        $this->assertTrue(self::waitUntil(fn (): bool => !self::alive($run['serverPid']), 5.0), 'The server is still running.');
    }

    /**
     * Starts the command and waits for its ready line.
     *
     * @param array<string, string|int|true> $options
     * @param array<string, string> $env
     * @return array{process: Process, output: string, serverPid: int}
     */
    private function serve(array $options, array $env = []): array
    {
        $process = $this->command($options, $env);
        $process->start();

        $ready = self::waitUntil(
            fn (): bool => str_contains($process->getOutput(), self::READY) || !$process->isRunning(),
            20.0,
        );
        $output = $process->getOutput() . $process->getErrorOutput();
        $this->assertTrue($ready && $process->isRunning(), "The command didn't become ready:\n{$output}");

        $children = self::children($process->getPid());
        $this->assertCount(1, $children, 'Expected one server process.');
        $this->serverPids[] = $children[0];

        return ['process' => $process, 'output' => $output, 'serverPid' => $children[0]];
    }

    /**
     * @param array<string, string|int|true> $options
     * @param array<string, string> $env
     */
    private function command(array $options, array $env = []): Process
    {
        $args = [PHP_BINARY, __DIR__ . '/Fixtures/artisan', 'dynoxide:serve', '--no-ansi'];
        foreach ($options as $name => $value) {
            $args[] = $value === true ? $name : "{$name}={$value}";
        }

        // The command writes its schema file to the system temporary
        // directory, which TMPDIR points at a directory this test watches.
        $process = new Process($args, dirname(__DIR__), $env + ['TMPDIR' => $this->dir . '/tmp'], null, null);
        $this->commands[] = $process;

        return $process;
    }

    /** @return list<string> */
    private static function tableNames(int $port): array
    {
        $names = self::client($port)->listTables()['TableNames'];
        sort($names);

        return $names;
    }

    private static function client(int $port): DynamoDbClient
    {
        return new DynamoDbClient([
            'region' => 'us-east-1',
            'endpoint' => "http://127.0.0.1:{$port}",
            'credentials' => ['key' => 'dynoxide', 'secret' => 'dynoxide'],
        ]);
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private static function answersGet(int $port, float $timeout = 1.0): bool
    {
        $socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, $timeout);
        if ($socket === false) {
            return false;
        }
        stream_set_timeout($socket, 1);
        fwrite($socket, "GET / HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nConnection: close\r\n\r\n");
        $status = fgets($socket);
        fclose($socket);

        return is_string($status) && str_starts_with($status, 'HTTP/1.1 200');
    }

    private static function portIsFree(int $port): bool
    {
        $socket = @stream_socket_server("tcp://127.0.0.1:{$port}");
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }

    /** @return list<int> */
    private static function children(int $pid): array
    {
        $pgrep = new Process(['pgrep', '-P', (string) $pid]);
        $pgrep->run();

        return array_map(intval(...), preg_split('/\s+/', trim($pgrep->getOutput()), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    private static function alive(int $pid): bool
    {
        if (!posix_kill($pid, 0)) {
            return false;
        }
        // A zombie still answers signal 0, but it has stopped serving.
        $ps = new Process(['ps', '-o', 'stat=', '-p', (string) $pid]);
        $ps->run();

        return !str_starts_with(trim($ps->getOutput()), 'Z');
    }

    private static function waitUntil(\Closure $condition, float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        while (!$condition()) {
            if (microtime(true) >= $deadline) {
                return false;
            }
            usleep(50_000);
        }

        return true;
    }

    /** @return list<string> */
    private static function filesIn(string $dir): array
    {
        return array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
    }

    private static function legacyBinary(): ?string
    {
        $candidates = getenv('DYNOXIDE_LEGACY_BINARY') !== false
            ? [(string) getenv('DYNOXIDE_LEGACY_BINARY')]
            : (glob(((string) getenv('HOME')) . '/.cache/dynoxide/1.6.0/*/dynoxide') ?: []);

        foreach ($candidates as $path) {
            if (!is_executable($path)) {
                continue;
            }
            $version = new Process([$path, '--version']);
            $version->run();
            if (trim($version->getOutput()) === 'dynoxide 1.6.0') {
                return $path;
            }
        }

        return null;
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (self::filesIn($dir) as $name) {
            $path = "{$dir}/{$name}";
            is_dir($path) && !is_link($path) ? self::removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
