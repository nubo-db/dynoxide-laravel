<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Console;

use Dynoxide\Binary\LocatedBinary;
use Dynoxide\Download\Url;
use Dynoxide\Dynoxide;
use Dynoxide\Exception\DynoxideException;
use Dynoxide\Laravel\Exception\ConfigException;
use Dynoxide\Laravel\TableDefinitions;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * Runs Dynoxide in the foreground for local development, with the tables the
 * app's config names already created.
 */
final class ServeCommand extends Command
{
    protected $signature = 'dynoxide:serve
        {--host= : The address to bind to [default: config dynoxide.host, 127.0.0.1]}
        {--port= : The port to listen on [default: config dynoxide.port, 8800]}
        {--db-path= : A database file that keeps data between runs [default: config dynoxide.db_path, or in memory]}
        {--timeout= : Seconds to wait for the server to be ready [default: config dynoxide.start_timeout, 10]}
        {--no-tables : Start without creating the app\'s DynamoDB tables}';

    protected $description = 'Run Dynoxide in the foreground with the app\'s DynamoDB tables';

    private const STOP_GRACE_SECONDS = 5;

    /** Dynoxide 1.8.0 and later exit with this when the port is taken. */
    private const EXIT_PORT_IN_USE = 4;

    private const POLL_MICROSECONDS = 25_000;

    private ?Process $process = null;

    private ?InputStream $stdin = null;

    private ?string $schemaFile = null;

    /** Whether the server's own "listening on" line has been left out yet. */
    private bool $listeningLineDropped = false;

    public function handle(): int
    {
        try {
            $host = $this->host();
            $port = $this->port();
            $timeout = $this->timeout();
            $dbPath = $this->dbPath();
            $tables = $this->option('no-tables') ? null : TableDefinitions::fromConfig($this->laravel['config']);
            $binary = Dynoxide::locate();
            $this->schemaFile = $tables === null ? null : $this->writeSchemaFile($tables);
        } catch (DynoxideException|ConfigException|\InvalidArgumentException $e) {
            $this->removeSchemaFile();
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        try {
            return $this->serve($binary, $host, $port, $timeout, $dbPath);
        } finally {
            $this->removeSchemaFile();
        }
    }

    private function serve(LocatedBinary $binary, string $host, int $port, float $timeout, ?string $dbPath): int
    {
        $this->line($binary->describe(), null, OutputInterface::VERBOSITY_VERBOSE);
        if (!$binary->launcherFlags) {
            $this->components->warn(
                "Dynoxide {$binary->version} predates --exit-with-parent, so the server keeps running if this "
                . 'command is killed rather than stopped. Dynoxide 1.7.0 or later stops with it.',
            );
        }

        // With --exit-with-parent the server shuts down when its stdin closes,
        // and Symfony Process closes a child's stdin when it has no input. The
        // stream stays open until stop() closes it, or until this process dies
        // and the operating system closes it, which is what takes the server
        // down after a kill.
        $this->stdin = new InputStream();
        $this->process = new Process($this->serverArguments($binary, $host, $port, $dbPath), null, null, $this->stdin, null);

        $this->trap(fn (): array => [SIGINT, SIGTERM, SIGHUP], function () use ($binary): void {
            $this->stop($binary);

            exit(self::SUCCESS);
        });

        $this->process->start();

        try {
            $ready = $this->waitUntilReady($binary, $timeout);
        } catch (\RuntimeException $e) {
            $this->stop($binary);
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($ready === null) {
            $status = $this->process->getExitCode();
            $this->components->error($status === self::EXIT_PORT_IN_USE
                ? "Port {$port} is already in use, so Dynoxide couldn't start on it. Stop what's using it, or start "
                    . 'Dynoxide on another port with --port, DYNOXIDE_PORT in .env or dynoxide.port in '
                    . 'config/dynoxide.php, and set DYNAMODB_ENDPOINT to match.'
                : 'Dynoxide exited with status ' . ($status ?? 'unknown') . ' before it was ready.');

            return $status === 0 || $status === null ? self::FAILURE : $status;
        }

        // The engine creates the tables before it reports ready, so the file
        // has done its job, and nothing is left behind if this process is killed.
        $this->removeSchemaFile();

        $this->components->info("Dynoxide running on [{$ready['endpoint']}].");
        $this->comment('  <fg=yellow;options=bold>Press Ctrl+C to stop</>');
        $this->newLine();
        if (!Url::isLoopback($ready['host'])) {
            $this->components->warn(
                "Dynoxide bound {$ready['host']}, which isn't a loopback address, so other machines may be able "
                . 'to reach it. Dynoxide accepts any credentials.',
            );
        }
        foreach ($this->storesPointingElsewhere($ready) as $path => $endpoint) {
            $this->components->warn(
                "{$path} is {$endpoint}, which isn't where Dynoxide is listening, so that store won't reach it. Set "
                . "DYNAMODB_ENDPOINT to {$ready['endpoint']} in .env, or start Dynoxide on that store's port.",
            );
        }

        while ($this->process->isRunning()) {
            $this->forwardOutput();
            usleep(self::POLL_MICROSECONDS);
        }
        $this->forwardOutput();

        $status = $this->process->getExitCode() ?? self::FAILURE;
        if ($status !== 0) {
            $this->components->error("Dynoxide exited with status {$status}.");
        }

        return $status;
    }

    /**
     * Each option and its value are one argument, so a path starting with "-"
     * can't be read as a flag.
     *
     * @return list<string>
     */
    private function serverArguments(LocatedBinary $binary, string $host, int $port, ?string $dbPath): array
    {
        $arguments = [$binary->path, 'serve', "--host={$host}", "--port={$port}"];
        if ($this->schemaFile !== null) {
            $arguments[] = "--schema={$this->schemaFile}";
        }
        if ($dbPath !== null) {
            $arguments[] = "--db-path={$dbPath}";
        }
        if ($binary->launcherFlags) {
            $arguments[] = '--exit-with-parent';
            $arguments[] = '--ready-json';
        }

        return $arguments;
    }

    /**
     * Waits for the server to say where it's listening: the JSON line on
     * stdout from a binary with --ready-json, or for an older one the
     * "listening on" line on stderr and then GET / answering. What the server
     * wrote before that is passed on, bar its own "listening on" line, which
     * the command's ready line replaces. Returns null if the server exited
     * first, after passing on what it wrote.
     *
     * @return array{endpoint: string, host: string}|null
     */
    private function waitUntilReady(LocatedBinary $binary, float $timeout): ?array
    {
        $process = $this->process;
        \assert($process !== null);
        $deadline = microtime(true) + $timeout;
        $stdout = '';
        $stderr = '';
        $listening = null;

        while (true) {
            $stdout .= $process->getIncrementalOutput();
            $stderr .= $process->getIncrementalErrorOutput();

            if ($binary->launcherFlags) {
                $end = strpos($stdout, "\n");
                if ($end !== false) {
                    // Before the line is read, since what the server wrote is
                    // what explains a line this command can't read.
                    $this->writeRaw($this->withoutListeningLine($stderr), error: true);
                    $ready = self::parseReadyLine(substr($stdout, 0, $end));
                    $this->writeRaw(substr($stdout, $end + 1));
                    $this->clearOutput();

                    return $ready;
                }
            } else {
                $listening ??= self::parseListeningLine($stderr);
                if ($listening !== null && self::answersGet($listening['endpoint'])) {
                    $this->writeRaw($this->withoutListeningLine($stderr), error: true);
                    $this->writeRaw($stdout);
                    $this->clearOutput();

                    return $listening;
                }
            }

            if (!$process->isRunning()) {
                $this->writeRaw($stdout . $process->getIncrementalOutput());
                $this->writeRaw($stderr . $process->getIncrementalErrorOutput(), error: true);

                return null;
            }
            if (microtime(true) >= $deadline) {
                $this->writeRaw($stderr, error: true);

                throw new \RuntimeException(sprintf(
                    'Dynoxide (%s) wasn\'t ready within %s. Give it longer with --timeout.',
                    $binary->describe(),
                    self::seconds($timeout),
                ));
            }
            usleep(self::POLL_MICROSECONDS);
        }
    }

    /**
     * Closes stdin first, so a server started with --exit-with-parent shuts
     * down cleanly by itself, and signals it only if it's still running after
     * that.
     */
    private function stop(LocatedBinary $binary): void
    {
        $process = $this->process;
        if ($process !== null && $process->isRunning()) {
            if ($binary->launcherFlags) {
                $this->stdin?->close();
                $deadline = microtime(true) + self::STOP_GRACE_SECONDS;
                while ($process->isRunning() && microtime(true) < $deadline) {
                    usleep(self::POLL_MICROSECONDS);
                }
            }
            if ($process->isRunning()) {
                $process->stop(self::STOP_GRACE_SECONDS);
            }
            $this->forwardOutput();
        }

        $this->removeSchemaFile();
    }

    /**
     * Passes on what the server wrote since the last call, and drops it from
     * the process's buffers, which would otherwise keep all of it for as long
     * as the server runs.
     *
     * Reading either stream reads both pipes, so each buffer is cleared
     * straight after its own read: stdout that arrives while stderr is read
     * stays in the buffer for the next call rather than being cleared unseen.
     */
    private function forwardOutput(): void
    {
        $process = $this->process;
        if ($process === null) {
            return;
        }
        $stdout = $process->getIncrementalOutput();
        $process->clearOutput();
        $stderr = $process->getIncrementalErrorOutput();
        $process->clearErrorOutput();

        $this->writeRaw($stdout);
        $this->writeRaw($this->withoutListeningLine($stderr), error: true);
    }

    private function clearOutput(): void
    {
        $this->process?->clearOutput();
        $this->process?->clearErrorOutput();
    }

    /**
     * Stderr without the server's "listening on" line, the first time it
     * appears: the command prints its own line saying where the server is.
     */
    private function withoutListeningLine(string $stderr): string
    {
        if ($this->listeningLineDropped) {
            return $stderr;
        }
        $stderr = (string) preg_replace('#^Dynoxide listening on \S+[ \t]*\r?\n#m', '', $stderr, 1, $count);
        $this->listeningLineDropped = $count > 0;

        return $stderr;
    }

    /**
     * The DynamoDB stores, and a DynamoDB failed-job store, whose configured
     * endpoint isn't this server, keyed by the config path of the endpoint.
     * A store with no endpoint is left out: what it reaches depends on the
     * environment, not on its config.
     *
     * @param array{endpoint: string, host: string} $ready
     * @return array<string, string>
     */
    private function storesPointingElsewhere(array $ready): array
    {
        $config = $this->laravel['config'];
        $endpoints = [];
        foreach ((array) $config->get('cache.stores', []) as $name => $store) {
            if (is_array($store) && ($store['driver'] ?? null) === 'dynamodb') {
                $endpoints["cache.stores.{$name}.endpoint"] = $store['endpoint'] ?? null;
            }
        }
        $failed = $config->get('queue.failed');
        if (is_array($failed) && ($failed['driver'] ?? null) === 'dynamodb') {
            $endpoints['queue.failed.endpoint'] = $failed['endpoint'] ?? null;
        }

        $elsewhere = [];
        foreach ($endpoints as $path => $endpoint) {
            if (is_string($endpoint) && $endpoint !== '' && !self::reaches($endpoint, $ready)) {
                $elsewhere[$path] = $endpoint;
            }
        }

        return $elsewhere;
    }

    /**
     * Whether a client configured with $endpoint reaches the server: the
     * same port, and the same host, or both loopback, or a server bound to
     * every address.
     *
     * @param array{endpoint: string, host: string} $ready
     */
    private static function reaches(string $endpoint, array $ready): bool
    {
        $client = parse_url($endpoint);
        $server = parse_url($ready['endpoint']);
        if (!is_array($client) || !isset($client['host']) || !is_array($server)) {
            return false;
        }
        $port = static fn (array $url): int => $url['port'] ?? (strtolower($url['scheme'] ?? 'http') === 'https' ? 443 : 80);
        if ($port($client) !== $port($server)) {
            return false;
        }
        $clientHost = strtolower(trim($client['host'], '[]'));
        $serverHost = strtolower(trim($ready['host'], '[]'));

        return $clientHost === $serverHost
            || in_array($serverHost, ['0.0.0.0', '::'], true)
            || (Url::isLoopback($clientHost) && Url::isLoopback($serverHost));
    }

    private function writeRaw(string $text, bool $error = false): void
    {
        if ($text === '') {
            return;
        }
        $output = $this->output->getOutput();
        if ($error && $output instanceof ConsoleOutputInterface) {
            $output = $output->getErrorOutput();
        }
        $output->write($text, false, OutputInterface::OUTPUT_RAW);
    }

    private function writeSchemaFile(TableDefinitions $tables): ?string
    {
        if ($tables->isEmpty()) {
            return null;
        }

        // In the system temporary directory rather than storage/, so nothing
        // lands where git would notice it. tempnam() creates it as 0600.
        $file = @tempnam(sys_get_temp_dir(), 'dynoxide-schema-');
        if ($file === false) {
            throw new \InvalidArgumentException(
                "Couldn't create the schema file in " . sys_get_temp_dir() . '. Check it is writable, or start with --no-tables.',
            );
        }
        if (file_put_contents($file, json_encode($tables->toSchemaJson(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) === false) {
            @unlink($file);

            throw new \InvalidArgumentException("Couldn't write the schema file {$file}.");
        }

        return $file;
    }

    private function removeSchemaFile(): void
    {
        if ($this->schemaFile !== null) {
            @unlink($this->schemaFile);
            $this->schemaFile = null;
        }
    }

    private function host(): string
    {
        $host = $this->setting('host', 'host', '127.0.0.1');
        if (!is_string($host) || trim($host) === '') {
            throw new \InvalidArgumentException('The host must be an address such as 127.0.0.1.');
        }

        return trim($host);
    }

    private function port(): int
    {
        $port = $this->setting('port', 'port', 8800);
        // Strictly, so "8800x" is an error rather than port 8800.
        $valid = is_int($port) || is_string($port)
            ? filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]])
            : false;
        if ($valid === false) {
            throw new \InvalidArgumentException(
                'The port (--port, or DYNOXIDE_PORT) must be a whole number from 1 to 65535, not ' . var_export($port, true) . '.',
            );
        }

        return $valid;
    }

    private function timeout(): float
    {
        $timeout = $this->setting('timeout', 'start_timeout', 10);
        $seconds = is_int($timeout) || is_float($timeout) || (is_string($timeout) && is_numeric(trim($timeout)))
            ? (float) $timeout
            : null;
        if ($seconds === null || !is_finite($seconds) || $seconds <= 0) {
            throw new \InvalidArgumentException(
                'The timeout (--timeout, or DYNOXIDE_START_TIMEOUT) must be a number of seconds more than 0, not '
                . var_export($timeout, true) . '.',
            );
        }

        return $seconds;
    }

    /**
     * The option if given, otherwise the config value, otherwise the
     * default. An empty config value, as a .env line with nothing after the
     * "=" gives, counts as none.
     */
    private function setting(string $option, string $key, int|string $default): mixed
    {
        $value = $this->option($option);
        if ($value !== null) {
            return $value;
        }
        $value = $this->laravel['config']->get("dynoxide.{$key}");

        return $value === null || (is_string($value) && trim($value) === '') ? $default : $value;
    }

    /** "1 second", "0.5 seconds", "10 seconds". */
    private static function seconds(float $seconds): string
    {
        $number = rtrim(rtrim(number_format($seconds, 3, '.', ''), '0'), '.');

        return $number === '1' ? '1 second' : "{$number} seconds";
    }

    private function dbPath(): ?string
    {
        $path = $this->option('db-path') ?? $this->laravel['config']->get('dynoxide.db_path');

        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * @return array{endpoint: string, host: string}
     */
    private static function parseReadyLine(string $line): array
    {
        $ready = json_decode($line, true);
        if (
            !is_array($ready)
            || ($ready['event'] ?? null) !== 'ready'
            || !is_string($ready['endpoint'] ?? null) || $ready['endpoint'] === ''
            || !is_string($ready['host'] ?? null) || $ready['host'] === ''
        ) {
            throw new \RuntimeException("Dynoxide printed a ready line this command can't read: {$line}");
        }

        return ['endpoint' => $ready['endpoint'], 'host' => $ready['host']];
    }

    /**
     * Only a whole line counts: half-written, "...:8080" could read as port 80.
     *
     * @return array{endpoint: string, host: string}|null
     */
    private static function parseListeningLine(string $stderr): ?array
    {
        if (preg_match('#Dynoxide listening on (http://(\[[0-9a-fA-F:.]+\]|[^\s:/]+):\d{1,5})[ \t]*\r?\n#', $stderr, $m) !== 1) {
            return null;
        }

        return ['endpoint' => $m[1], 'host' => trim($m[2], '[]')];
    }

    private static function answersGet(string $endpoint): bool
    {
        $address = substr($endpoint, strlen('http://'));
        $socket = @stream_socket_client("tcp://{$address}", $errno, $error, 0.5);
        if ($socket === false) {
            return false;
        }
        stream_set_timeout($socket, 0, 500_000);
        @fwrite($socket, "GET / HTTP/1.1\r\nHost: {$address}\r\nConnection: close\r\n\r\n");
        $status = @fgets($socket, 256);
        fclose($socket);

        return is_string($status) && preg_match('#^HTTP/1\.[01] 200\b#', $status) === 1;
    }
}
