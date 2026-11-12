<?php

declare(strict_types=1);

use Dynoxide\Laravel\Testing\DynoxideProcess;
use Dynoxide\Laravel\Testing\UsesDynoxide;
use Dynoxide\Laravel\Tests\Support\PestTestCase;
use Illuminate\Support\Facades\Cache;

uses(PestTestCase::class, UsesDynoxide::class);

it('shares the one server the process started and makes each table once', function (): void {
    expect(DynoxideProcess::starts())->toBe(1)
        ->and(getenv('AWS_ENDPOINT_URL_DYNAMODB'))->toBe($this->dynoxideServer()->endpoint);

    $created = DynoxideProcess::tablesCreated();
    expect($created)->toHaveKeys(['cache', 'pest_file'])
        ->and(array_unique(array_values($created)))->toBe([1]);
});

it('puts a value through the cache', function (): void {
    Cache::store('pest')->put('file', 'pest', 60);

    expect(Cache::store('pest')->get('file'))->toBe('pest');
});

it('starts the next test without it', function (): void {
    expect(Cache::store('pest')->get('file'))->toBeNull();
})->depends('it puts a value through the cache');
