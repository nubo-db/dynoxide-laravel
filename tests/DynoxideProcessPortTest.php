<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests;

use Dynoxide\Exception\DynoxideException;
use Dynoxide\Laravel\Exception\ConfigException;
use Dynoxide\Laravel\Testing\DynoxideProcess;
use Dynoxide\Options;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * A `port` in dynoxideOptions(): kept when a worker gets its own database
 * file, and refused where each process starts a server of its own.
 */
final class DynoxideProcessPortTest extends PHPUnitTestCase
{
    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
        $this->savedEnv = [];
    }

    public function test_a_port_is_kept_when_the_worker_gets_its_own_database_file(): void
    {
        $this->setEnv('PARATEST', '1');
        $this->setEnv('TEST_TOKEN', '3');
        $forThisWorker = new \ReflectionMethod(DynoxideProcess::class, 'forThisWorker');

        $options = $forThisWorker->invoke(null, new Options(dbPath: '/tmp/app.db', port: 8123));

        $this->assertSame(8123, $options->port);
        $this->assertSame('/tmp/app.db-3', $options->dbPath);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function fixedPortOptions(): iterable
    {
        yield 'in memory' => [['port' => 8123]];
        yield 'with a database file' => [['port' => 8123, 'dbPath' => '/tmp/app.db']];
    }

    /**
     * The ConfigException comes as it is, not wrapped in the start failure,
     * and nothing is started.
     *
     * @param array<string, mixed> $options
     */
    #[DataProvider('fixedPortOptions')]
    public function test_a_fixed_port_under_paratest_is_refused(array $options): void
    {
        $this->setEnv('PARATEST', '1');
        $this->setEnv('TEST_TOKEN', '1');
        $starts = DynoxideProcess::starts();

        try {
            DynoxideProcess::server(self::class, $options);
            $this->fail('A fixed port was accepted under ParaTest.');
        } catch (ConfigException $e) {
            $this->assertStringContainsString('asks for port 8123', $e->getMessage());
            $this->assertStringContainsString('ParaTest runs each worker with a server of its own', $e->getMessage());
        }
        $this->assertSame($starts, DynoxideProcess::starts());
    }

    #[RunInSeparateProcess]
    public function test_a_fixed_port_in_a_separate_process_is_refused(): void
    {
        // The child inherits a ParaTest run's environment, whose message comes first.
        putenv('PARATEST');
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('a test that runs in a separate process starts a server of its own');

        DynoxideProcess::server(self::class, ['port' => 8123]);
    }

    /**
     * The README says a port from an environment variable needs an (int)
     * cast, and quotes the core's error for a string. This pins that wording.
     */
    public function test_a_port_given_as_a_string_gets_the_cores_wrong_type_error(): void
    {
        $this->expectException(DynoxideException::class);
        $this->expectExceptionMessage('must be of type int, string given');

        Options::fromArray(['port' => '8123']);
    }

    private function setEnv(string $name, string $value): void
    {
        if (!array_key_exists($name, $this->savedEnv)) {
            $this->savedEnv[$name] = getenv($name);
        }
        putenv("{$name}={$value}");
    }
}
