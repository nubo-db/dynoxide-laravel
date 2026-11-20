<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests;

use Dynoxide\Laravel\Console\ServeCommand;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

/**
 * dynoxide:serve run in the test's own process: the input checks, which fail
 * before any server starts, and, with a fake binary that stops by itself
 * once it has reported ready, what the command prints around a start.
 */
final class ServeCommandInProcessTest extends TestCase
{
    private const FAKE = __DIR__ . '/Fixtures/fake-dynoxide';

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
            unset($_ENV[$name], $_SERVER[$name]);
            if ($value !== false) {
                $_ENV[$name] = $_SERVER[$name] = $value;
            }
        }
        $this->savedEnv = [];

        parent::tearDown();
    }

    public function test_a_port_that_is_not_a_number_fails_before_anything_starts(): void
    {
        // Never reached once the port is checked, but without that check a
        // server that never gets ready can't keep the test waiting.
        $this->useFake(['FAKE_DYNOXIDE_MODE' => 'never']);

        $this->artisan('dynoxide:serve', ['--port' => 'abc', '--timeout' => '1', '--no-tables' => true])
            ->expectsOutputToContain("The port (--port, or DYNOXIDE_PORT) must be a whole number from 1 to 65535, not 'abc'.")
            ->assertFailed();
    }

    /** @return iterable<string, array{string}> */
    public static function portsWithSomethingAfterTheNumber(): iterable
    {
        yield 'letter O for zero' => ['88OO'];
        yield 'a trailing letter' => ['8800x'];
    }

    /**
     * Read strictly, so a typo isn't quietly taken as the number it starts
     * with.
     */
    #[DataProvider('portsWithSomethingAfterTheNumber')]
    public function test_a_dynoxide_port_that_is_not_a_whole_number_is_an_error_naming_it(string $port): void
    {
        $this->setEnv('DYNOXIDE_PORT', $port);
        $this->refreshApplication();
        // Never reached once the port is checked, but without that check a
        // server that never gets ready can't keep the test waiting.
        $this->useFake(['FAKE_DYNOXIDE_MODE' => 'never']);

        $this->artisan('dynoxide:serve', ['--timeout' => '1', '--no-tables' => true])
            ->expectsOutputToContain("The port (--port, or DYNOXIDE_PORT) must be a whole number from 1 to 65535, not '{$port}'.")
            ->assertFailed();
    }

    public function test_a_dynoxide_start_timeout_that_is_not_a_number_is_an_error_naming_it(): void
    {
        $this->setEnv('DYNOXIDE_START_TIMEOUT', '10s');
        $this->refreshApplication();
        $this->useFake(['FAKE_DYNOXIDE_MODE' => 'never']);

        $this->artisan('dynoxide:serve', ['--port' => 8899, '--no-tables' => true])
            ->expectsOutputToContain("The timeout (--timeout, or DYNOXIDE_START_TIMEOUT) must be a number of seconds more than 0, not '10s'.")
            ->assertFailed();
    }

    /** @return iterable<string, array{string}> */
    public static function emptyValues(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['  '];
    }

    /**
     * An empty DYNOXIDE_PORT or DYNOXIDE_HOST, as a .env line with nothing
     * after the "=" gives, means the default.
     */
    #[DataProvider('emptyValues')]
    #[Group('posix')]
    public function test_an_empty_dynoxide_port_or_host_falls_back_to_the_default(string $value): void
    {
        $this->setEnv('DYNOXIDE_PORT', $value);
        $this->setEnv('DYNOXIDE_HOST', $value);
        $this->refreshApplication();
        $this->useFake();

        $this->artisan('dynoxide:serve', ['--timeout' => '5', '--no-tables' => true])
            ->expectsOutputToContain('Dynoxide running on [http://127.0.0.1:8800].')
            ->assertSuccessful();
    }

    #[DataProvider('emptyValues')]
    #[Group('posix')]
    public function test_an_empty_dynoxide_start_timeout_falls_back_to_the_default(string $value): void
    {
        $this->setEnv('DYNOXIDE_START_TIMEOUT', $value);
        $this->refreshApplication();
        $this->useFake();

        $this->artisan('dynoxide:serve', ['--port' => 8899, '--no-tables' => true])
            ->doesntExpectOutputToContain('must be a number')
            ->assertSuccessful();
    }

    /** @return iterable<string, array{int|string}> */
    public static function configuredPorts(): iterable
    {
        yield 'a string with a leading space' => [' 8800'];
        yield 'an int' => [8800];
    }

    #[DataProvider('configuredPorts')]
    #[Group('posix')]
    public function test_a_port_from_the_config_is_read_as_a_number(int|string $port): void
    {
        config(['dynoxide.port' => $port]);
        $this->useFake();

        $this->artisan('dynoxide:serve', ['--timeout' => '5', '--no-tables' => true])
            ->expectsOutputToContain('Dynoxide running on [http://127.0.0.1:8800].')
            ->assertSuccessful();
    }

    /** @return iterable<string, array{float|string}> */
    public static function fractionalTimeouts(): iterable
    {
        yield 'a string' => ['2.5'];
        yield 'a float' => [2.5];
    }

    #[DataProvider('fractionalTimeouts')]
    #[Group('posix')]
    public function test_a_fractional_timeout_from_the_config_is_kept(float|string $timeout): void
    {
        config(['dynoxide.start_timeout' => $timeout]);
        $this->useFake(['FAKE_DYNOXIDE_MODE' => 'never']);

        $this->artisan('dynoxide:serve', ['--port' => 8899, '--no-tables' => true])
            ->expectsOutputToContain("wasn't ready within 2.5 seconds")
            ->assertFailed();
    }

    public function test_a_store_in_use_with_no_table_names_its_config_key(): void
    {
        config(['cache.default' => 'dynamodb', 'cache.stores.dynamodb.table' => null]);
        // Never reached once the table is checked, but without that check
        // a server that never gets ready can't keep the test waiting.
        $this->useFake(['FAKE_DYNOXIDE_MODE' => 'never']);

        $this->artisan('dynoxide:serve', ['--port' => 8899, '--timeout' => '1'])
            ->expectsOutputToContain('cache.stores.dynamodb.table must name the DynamoDB table')
            ->assertFailed();
    }

    /** @return iterable<string, array{string}> */
    public static function badTimeouts(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'not a number' => ['soon'];
    }

    #[DataProvider('badTimeouts')]
    public function test_a_timeout_that_is_not_a_positive_number_fails(string $timeout): void
    {
        $this->artisan('dynoxide:serve', ['--timeout' => $timeout])
            ->expectsOutputToContain('The timeout (--timeout, or DYNOXIDE_START_TIMEOUT) must be a number of seconds more than 0')
            ->assertFailed();
    }

    public function test_the_timeout_falls_back_to_the_config(): void
    {
        config(['dynoxide.start_timeout' => 'soon']);
        // Never reached once the timeout is checked, but without that check
        // a server that never gets ready can't keep the test waiting.
        $this->useFake(['FAKE_DYNOXIDE_MODE' => 'never']);

        $this->artisan('dynoxide:serve', ['--port' => 8899, '--no-tables' => true])
            ->expectsOutputToContain("The timeout (--timeout, or DYNOXIDE_START_TIMEOUT) must be a number of seconds more than 0, not 'soon'.")
            ->assertFailed();
    }

    #[Group('posix')]
    public function test_the_timeout_is_how_long_it_waits_for_the_server(): void
    {
        $this->useFake(['FAKE_DYNOXIDE_MODE' => 'never']);
        $started = microtime(true);

        $this->artisan('dynoxide:serve', ['--port' => 8899, '--timeout' => '0.5', '--no-tables' => true])
            ->expectsOutputToContain("wasn't ready within 0.5 seconds")
            ->assertFailed();

        $this->assertLessThan(5.0, microtime(true) - $started);
    }

    #[Group('posix')]
    public function test_a_binding_that_is_not_loopback_gets_a_warning(): void
    {
        $this->useFake();

        $this->artisan('dynoxide:serve', ['--host' => '0.0.0.0', '--port' => 8899, '--no-tables' => true])
            ->expectsOutputToContain("Dynoxide bound 0.0.0.0, which isn't a loopback address")
            ->assertSuccessful();
    }

    #[Group('posix')]
    public function test_a_loopback_binding_gets_no_warning(): void
    {
        $this->useFake();

        $this->artisan('dynoxide:serve', ['--port' => 8899, '--no-tables' => true])
            ->expectsOutputToContain('Dynoxide running on [http://127.0.0.1:8899].')
            ->doesntExpectOutputToContain('loopback')
            ->assertSuccessful();
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function endpointsElsewhere(): iterable
    {
        yield 'a cache store' => [['cache.stores.dynamodb.endpoint' => 'http://127.0.0.1:8800'], 'cache.stores.dynamodb.endpoint'];
        yield 'a store the app does not use' => [
            ['cache.stores.other' => ['driver' => 'dynamodb', 'table' => 'other', 'endpoint' => 'http://localhost:9000']],
            'cache.stores.other.endpoint',
        ];
        yield 'the failed-job store' => [
            ['queue.failed' => ['driver' => 'dynamodb', 'table' => 'failed_jobs', 'endpoint' => 'http://127.0.0.1:8800']],
            'queue.failed.endpoint',
        ];
    }

    /** @param array<string, mixed> $config */
    #[Group('posix')]
    #[DataProvider('endpointsElsewhere')]
    public function test_a_store_pointing_at_another_port_gets_a_warning(array $config, string $path): void
    {
        config($config);
        $this->useFake();

        [$status, $output] = $this->serve(['--port' => 8899, '--no-tables' => true]);

        $this->assertSame(0, $status, $output);
        $this->assertMatchesRegularExpression('/' . preg_quote($path, '/') . ' is http:.*DYNAMODB_ENDPOINT to http:\/\/127\.0\.0\.1:8899/', $output);
    }

    #[Group('posix')]
    public function test_a_store_pointing_at_the_server_gets_no_warning(): void
    {
        config([
            'cache.stores.dynamodb.endpoint' => 'http://localhost:8899',
            'cache.stores.other' => ['driver' => 'dynamodb', 'table' => 'other', 'endpoint' => 'http://127.0.0.1:8899/'],
            'cache.stores.unset' => ['driver' => 'dynamodb', 'table' => 'unset', 'endpoint' => null],
        ]);
        $this->useFake();

        [$status, $output] = $this->serve(['--port' => 8899, '--no-tables' => true]);

        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('Dynoxide running on', $output);
        $this->assertStringNotContainsString('DYNAMODB_ENDPOINT', $output);
    }

    /** @return iterable<string, array{string}> */
    public static function versions(): iterable
    {
        yield 'with --ready-json' => ['1.7.0'];
        yield 'before --ready-json' => ['1.6.0'];
    }

    #[Group('posix')]
    #[DataProvider('versions')]
    public function test_what_the_server_writes_before_it_is_ready_is_passed_on_bar_the_listening_line(string $version): void
    {
        $this->useFake(['FAKE_DYNOXIDE_VERSION' => $version, 'FAKE_DYNOXIDE_STDERR' => 'warning: something at start-up']);

        $this->artisan('dynoxide:serve', ['--port' => self::freePort(), '--no-tables' => true])
            ->expectsOutputToContain('warning: something at start-up')
            ->expectsOutputToContain('Dynoxide running on')
            ->doesntExpectOutputToContain('Dynoxide listening on')
            ->assertSuccessful();
    }

    /**
     * The server's stderr is what explains a ready line the command can't
     * read, so it's passed on before the error.
     */
    #[Group('posix')]
    public function test_an_unreadable_ready_line_still_passes_on_what_the_server_wrote_first(): void
    {
        $this->useFake(['FAKE_DYNOXIDE_MODE' => 'garbled', 'FAKE_DYNOXIDE_STDERR' => 'warning: something before the ready line']);

        [$status, $output] = $this->serve(['--port' => 8899, '--no-tables' => true]);

        $this->assertSame(1, $status, $output);
        $this->assertMatchesRegularExpression(
            "/warning: something before the ready line.*printed a ready line this command can't read: this is not a ready line/s",
            $output,
        );
    }

    #[Group('posix')]
    public function test_the_servers_own_exit_status_is_passed_through(): void
    {
        $this->useFake(['FAKE_DYNOXIDE_MODE' => 'exit:3', 'FAKE_DYNOXIDE_STDERR' => 'error: it went wrong']);

        $this->artisan('dynoxide:serve', ['--port' => 8899, '--no-tables' => true])
            ->expectsOutputToContain('error: it went wrong')
            ->expectsOutputToContain('Dynoxide exited with status 3 before it was ready.')
            ->assertExitCode(3);
    }

    #[Group('posix')]
    public function test_a_taken_port_is_named_with_how_to_change_it(): void
    {
        $this->useFake(['FAKE_DYNOXIDE_MODE' => 'exit:4', 'FAKE_DYNOXIDE_STDERR' => 'error: port 8899 is already in use']);

        [$status, $output] = $this->serve(['--port' => 8899, '--no-tables' => true]);

        $this->assertSame(4, $status, $output);
        $this->assertStringContainsString('Port 8899 is already in use', $output);
        $this->assertStringContainsString('DYNOXIDE_PORT', $output);
        $this->assertStringNotContainsString('before it was ready', $output);
    }

    /**
     * The process keeps everything its child writes unless it's told to
     * drop it, so a server left running for days would fill the disk with
     * output that has already been shown.
     */
    public function test_forwarded_output_is_not_kept(): void
    {
        $process = new Process([PHP_BINARY, '-r', 'echo str_repeat("o", 4096); fwrite(STDERR, str_repeat("e", 4096));']);
        $process->run();
        $buffer = new BufferedOutput();
        $command = $this->app->make(ServeCommand::class);
        $command->setLaravel($this->app);
        $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));
        (function (Process $process): void {
            $this->process = $process;
            $this->forwardOutput();
        })->call($command, $process);

        $this->assertSame(str_repeat('o', 4096) . str_repeat('e', 4096), $buffer->fetch());
        $this->assertSame('', $process->getOutput());
        $this->assertSame('', $process->getErrorOutput());
    }

    /**
     * Reading either stream reads both pipes, so stdout can arrive while
     * stderr is being read. Clearing stdout after that would lose it.
     */
    public function test_stdout_that_arrives_while_stderr_is_read_is_not_lost(): void
    {
        $process = new class ([PHP_BINARY]) extends Process {
            public string $out = 'first ';

            public string $err = '';

            public function getIncrementalOutput(): string
            {
                [$new, $this->out] = [$this->out, ''];

                return $new;
            }

            public function getIncrementalErrorOutput(): string
            {
                // What a read of the pipes does when stdout has more.
                $this->out .= 'arrived meanwhile';
                [$new, $this->err] = [$this->err, ''];

                return $new;
            }

            public function clearOutput(): static
            {
                $this->out = '';

                return $this;
            }

            public function clearErrorOutput(): static
            {
                $this->err = '';

                return $this;
            }
        };
        $buffer = new BufferedOutput();
        $command = $this->app->make(ServeCommand::class);
        $command->setLaravel($this->app);
        $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

        (function (Process $process): void {
            $this->process = $process;
            $this->forwardOutput();
            $this->forwardOutput();
        })->call($command, $process);

        $this->assertStringContainsString('first arrived meanwhile', $buffer->fetch());
    }

    /**
     * Runs the command and returns its status and everything it wrote. One
     * write can meet only one of expectsOutputToContain()'s expectations, so
     * this is for checking several things in one line.
     *
     * @param array<string, mixed> $options
     * @return array{int, string}
     */
    private function serve(array $options): array
    {
        $this->withoutMockingConsoleOutput();
        $status = Artisan::call('dynoxide:serve', $options);

        return [$status, Artisan::output()];
    }

    /** @param array<string, string> $env */
    private function useFake(array $env = []): void
    {
        foreach ($env + ['DYNOXIDE_BINARY' => self::FAKE, 'FAKE_DYNOXIDE_VERSION' => '1.7.0', 'FAKE_DYNOXIDE_MODE' => 'ready', 'FAKE_DYNOXIDE_STDERR' => ''] as $name => $value) {
            $this->setEnv($name, $value);
        }
    }

    /** Sets a variable until the test ends. */
    private function setEnv(string $name, string $value): void
    {
        if (!array_key_exists($name, $this->savedEnv)) {
            $this->savedEnv[$name] = getenv($name);
        }
        // Symfony Process passes on only the variables $_SERVER has too.
        putenv("{$name}={$value}");
        $_ENV[$name] = $_SERVER[$name] = $value;
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
