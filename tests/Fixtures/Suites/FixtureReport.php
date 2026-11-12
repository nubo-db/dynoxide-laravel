<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests\Fixtures\Suites;

/**
 * What a fixture test saw, one JSON line each, for the test that ran it.
 */
final class FixtureReport
{
    /**
     * @param array<string, mixed> $data
     */
    public static function record(string $label, array $data = []): void
    {
        $line = json_encode(['label' => $label, 'pid' => getmypid(), 'token' => (string) getenv('TEST_TOKEN')] + $data, JSON_THROW_ON_ERROR);
        file_put_contents((string) getenv('FIXTURE_REPORT'), $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
