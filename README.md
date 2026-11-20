# Dynoxide for Laravel

Run Laravel's DynamoDB cache, locks, rate limiter, sessions and failed jobs
against [Dynoxide](https://github.com/nubo-db/dynoxide), a DynamoDB-compatible
engine, in your tests and on your machine. It builds on the
[core PHP package](https://github.com/nubo-db/dynoxide-php), which downloads,
verifies and starts the Dynoxide binary, and adds the Laravel parts:

- a testing trait, `UsesDynoxide`, that starts Dynoxide once per test
  process, points the app's DynamoDB stores at it, creates the tables your
  config names, and empties them after each test;
- `php artisan dynoxide:serve`, which runs Dynoxide on port 8800 for local
  development, with those tables already created.

It reads your app's own config, so custom table names, custom key attributes
and extra `dynamodb` stores need no extra setup.

## Install

```sh
composer require --dev nubo-db/dynoxide-laravel
```

It needs PHP 8.2 or later and Laravel 12 or 13. It pulls in
`aws/aws-sdk-php`, which Laravel's DynamoDB drivers need but don't install.
Laravel discovers the service provider, so there's nothing to register.

The Dynoxide binary comes from the core package: it's downloaded and
checksum-verified on first use, or you can point `DYNOXIDE_BINARY` at one you
already have. The core package's README covers
[the download, the cache and your own binary](https://github.com/nubo-db/dynoxide-php#ci).

## Tests

Put the trait on your base `TestCase`:

```php
namespace Tests;

use Dynoxide\Laravel\Testing\UsesDynoxide;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use UsesDynoxide;
}
```

Every test needs the server, and the base class is where they all get it.
One server serves the whole PHP process, however many classes use the
trait. With Pest, `pest()->extend(Tests\TestCase::class)` in `tests/Pest.php`
brings it in, or `uses(UsesDynoxide::class)` in a single file.

On the base class, every test needs the Dynoxide binary, including tests
that never touch DynamoDB. On a CI runner with no network the first
download fails and so does the whole suite, unless `DYNOXIDE_BINARY` points
at a binary already on the machine.

The trait never changes which drivers your app uses. A stock `phpunit.xml`
sets the cache and sessions to `array`, so change those lines to use
DynamoDB, and add the failed-job one if you want failed jobs there too:

```xml
<env name="CACHE_STORE" value="dynamodb"/>
<env name="SESSION_DRIVER" value="dynamodb"/>
<env name="QUEUE_FAILED_DRIVER" value="dynamodb"/>
```

If nothing in the app uses DynamoDB (judged from `cache.default`,
`cache.limiter`, the session driver and store, the stores behind a failover
store among those, and `queue.failed.driver`), the trait prints a notice
once per process naming these lines, so a suite that never touches
Dynoxide doesn't pass quietly.

DynamoDB has no way to empty a table in one call, so Laravel's
`DynamoDbStore::flush()` throws. With `CACHE_STORE=dynamodb`, any test that
calls `Cache::flush()` or runs `cache:clear` now fails. The trait's reset
after each test does that job instead, so those calls can go.

### What the trait does

Before the first test in a process creates its app, the trait starts
Dynoxide on a free port and exports its endpoint to the environment as
`DYNAMODB_ENDPOINT` and `AWS_ENDPOINT_URL_DYNAMODB`, with
`AWS_IGNORE_CONFIGURED_ENDPOINT_URLS=false` so a shared AWS config file
can't send the SDK to AWS instead. A stock config reads those as the app
boots.

Credentials and a region wait until the first app has booted, so that its
`.env` or `.env.testing` is in the environment by then. Laravel never
overwrites a variable that's already set, so exporting them any earlier
would replace the app's own. Then, once per process, the trait sets
`AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` to dummy values, and empties
`AWS_SESSION_TOKEN`, only if no access key is set anywhere, and sets
`AWS_REGION` and `AWS_DEFAULT_REGION` only if neither is set. An empty
line such as `AWS_ACCESS_KEY_ID=`, as in Laravel's own `.env.example`,
counts as not set, and the dummy value then stays in place for every later
test rather than being blanked when the next app loads the file.

So credentials and a region your tests give S3 or SQS, against MinIO or
LocalStack say, stay as they are, wherever they come from: the shell,
`phpunit.xml`, `.env` or `.env.testing`. Shell credentials from aws-vault or
an SSO login are kept too, so a client your tests don't fake can still
reach AWS with them. The DynamoDB stores don't need any of these: the trait
sets their credentials in the config, below.

With the core PHPUnit extension running the server
([below](#with-the-core-phpunit-extension)), the trait exports nothing. The
extension's export applies instead, and that always sets the dummy
credentials and region.

Then, in every test, once the app has booted, it:

- sets `endpoint`, `key`, `secret` and `region` to the server's on every cache
  store whose driver is `dynamodb`, and on `queue.failed` when its driver is
  `dynamodb`, and clears `token`, so a generated `queue.failed` block, which
  has none of them, works;
- drops anything `boot()` already resolved with the old settings: those cache
  stores, their `Cache::memo()` copies, the default store, DynamoDB sessions
  and the failed-job provider;
- rebuilds the rate limiter on the repointed store when it uses a DynamoDB
  store, keeping the named limiters your providers registered with
  `RateLimiter::for()`;
- creates any table the config names that doesn't exist yet: one for each
  `dynamodb` cache store, keyed on its `attributes.key` (`key` by default),
  and `queue.failed.table`, keyed on `application` and `uuid`. Sessions use a
  cache store's table. A table a test deleted is created again before the
  next test.

A `dynamodb` store with no `table` is skipped unless the app uses it (as the
default store, the rate limiter's, the session store, or behind a failover
store that is one of those), so a store whose table only production names
doesn't fail the tests. One the app does use fails with an error naming its
config key.

If two stores, or a store and the failed-job config, name the same table with
different keys, the test fails with an error naming both config paths. If a
table that's already there (in a database file from an earlier run, say) has
a different key from the one the config asks for, the error names the table
and both keys.

After each test it deletes every item in those tables, so a cached value, a
rate-limiter hit or a `ShouldBeUnique` lock doesn't carry into the next test.
Laravel's `DynamoDbStore::flush()` throws, so there's no other way to clear
them. Tables a test creates for itself aren't touched.

To keep items from one test to the next, turn the reset off in the test case:

```php
protected function dynoxideResetsTables(): bool
{
    return false;
}
```

For a client of your own in a test, `$this->dynoxideServer()` returns the
running server:

```php
$client = new DynamoDbClient($this->dynoxideServer()->clientConfig());
```

### Options

`dynoxideOptions()` takes the options the core package's `Dynoxide::start()`
does. To keep the data in a file between runs:

```php
protected function dynoxideOptions(): array
{
    return ['dbPath' => dirname(__DIR__) . '/storage/framework/testing/dynoxide.db'];
}
```

It's called before the app exists, so Laravel's helpers such as
`storage_path()` don't work in it.

`port` fixes the port the server listens on, where something outside the
tests needs to know it in advance. To set it from outside the code, use an
environment variable that's there before the app boots, from the shell, CI or
an `<env>` in `phpunit.xml`, not `.env`: the first call comes before Laravel
loads `.env`, so the second would ask for a different port and fail. An
environment variable is a string, so cast it:
`['port' => (int) getenv('DYNOXIDE_TEST_PORT')]`. Without the cast the core
refuses it with "must be of type int, string given". If the port is
taken, the tests fail with a `StartException` that names it, whose previous
exception is the core's `PortInUseException`. Under
ParaTest, and in a test that runs in a separate process, a fixed port is
refused, since each of those processes starts a server of its own.

A process runs one server, so every test case in it must return the same
options, or the second one fails with an error naming both classes. Put
`dynoxideOptions()` on the base `TestCase` too.

Unless the reset is off, the trait also empties the config's tables at the
start of a process, so items left in a database file by an earlier run don't
reach the first test.

### Reading the cache in `boot()`

The server is running before the first app boots, but the tables come from
the app's config, so the trait can only create them once that app exists. A
provider that reads or writes the DynamoDB cache in `boot()` therefore gets a
`ResourceNotFoundException` in the first test of each process (each ParaTest
worker is a process). From the second test on, the tables are there and it
works.

There are two ways round it: a `dbPath` whose file already holds the tables
from an earlier run, or the core PHPUnit extension with a `schema` file that
creates them before any test starts ([below](#with-the-core-phpunit-extension)).
Either way, a read in that first `boot()` happens before the trait has
emptied the tables, so it can see an earlier run's items.

In that first `boot()` the dummy credentials aren't in the environment yet
either, so a read there uses whatever credentials the run has. With none at
all it fails for that reason instead. Setting `AWS_ACCESS_KEY_ID` and
`AWS_SECRET_ACCESS_KEY` to any value in `phpunit.xml` gets past that, since
Dynoxide accepts any credentials.

A provider that only resolves a store in `boot()`, without calling DynamoDB,
is fine: the trait swaps it for a repointed one.

### Failed jobs resolved in `boot()`

Laravel's DynamoDB failed-job provider reads `queue.failed.region` with no
default, and the generated `queue.failed` block doesn't have one. The trait
sets it in each test, but only after the app has booted, so if a provider
resolves `queue.failer` in `boot()`, that test fails with
`Undefined array key "region"` before the trait runs, and the trait can't
fix it. Give the block a region in `config/queue.php`:

```php
'failed' => [
    'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
    'database' => env('DB_CONNECTION', 'sqlite'),
    'table' => 'failed_jobs',
    'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
],
```

With a region there, the provider resolved in `boot()` is replaced with one
that reaches Dynoxide, like the cache stores are.

### ParaTest and separate processes

Under ParaTest, and so `php artisan test --parallel` and Pest's `--parallel`,
each worker is its own process, so it starts its own server and creates its
own tables, and workers never see each other's data. With `dbPath` set, each
worker gets its own file with the worker's number on the end:
`dynoxide.db-1`, `dynoxide.db-2` and so on. ParaTest's `--no-test-tokens`
takes those numbers away, so it's refused when `dbPath` is set.

A test marked `#[RunInSeparateProcess]` (or `@runInSeparateProcess` on
PHPUnit 11) runs in a new PHP process, which starts a server of its own for
that one test and creates the tables again, writing nothing to stderr.

### With the core PHPUnit extension

If `phpunit.xml` also loads the core package's extension, the trait uses the
server the extension started rather than starting one of its own:

```xml
<extensions>
    <bootstrap class="Dynoxide\PHPUnit\Extension">
        <parameter name="schema" value="tests/dynoxide-schema.json"/>
    </bootstrap>
</extensions>
```

It still repoints the app's stores at that server, creates any table the
schema didn't, and empties the config's tables after each test, including
ones the schema file created. The extension's parameters decide the database
file and schema, so a test case that returns options from `dynoxideOptions()`
fails with an error saying they belong in `phpunit.xml`.

If the extension failed to start its server, the trait's tests fail with
that error. The trait doesn't start a second server, which would ignore the
extension's settings.

A test run in a separate process has no extension server, so it starts its
own, without the extension's schema.

## Local development

`php artisan dynoxide:serve` runs Dynoxide in the foreground with your app's
tables already created:

```console
$ php artisan dynoxide:serve

   INFO  Dynoxide running on [http://127.0.0.1:8800].

  Press Ctrl+C to stop
```

It uses port 8800 so it stays out of the way of `php artisan serve` on 8000.
Point the app at it in `.env`:

```dotenv
DYNAMODB_ENDPOINT=http://127.0.0.1:8800
AWS_ACCESS_KEY_ID=dynoxide
AWS_SECRET_ACCESS_KEY=dynoxide
AWS_DEFAULT_REGION=us-east-1
CACHE_STORE=dynamodb
SESSION_DRIVER=dynamodb
```

Dynoxide accepts any credentials, but Laravel only sends them when both the
key and the secret are set, and otherwise goes looking for real ones.

The trait isn't involved here, so for failed jobs the `queue.failed` block in
`config/queue.php` needs the same settings the cache store has, then
`QUEUE_FAILED_DRIVER=dynamodb` in `.env`:

```php
'failed' => [
    'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
    'database' => env('DB_CONNECTION', 'sqlite'),
    'table' => 'failed_jobs',
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    'endpoint' => env('DYNAMODB_ENDPOINT'),
],
```

The data lives in memory and goes when the command stops. The options are:

| Option | What it does |
|---|---|
| `--port` | The port to listen on. Defaults to 8800, or `DYNOXIDE_PORT`. |
| `--host` | The address to bind to. Defaults to `127.0.0.1`, or `DYNOXIDE_HOST`. |
| `--db-path` | Keep the data in this file, so it survives a restart. Defaults to `DYNOXIDE_DB_PATH`. |
| `--timeout` | Seconds to wait for Dynoxide to be ready. Defaults to 10, or `DYNOXIDE_START_TIMEOUT`. |
| `--no-tables` | Start without creating the app's tables. |

With Dynoxide 1.8.0 or later, if the port is taken the command says so,
names the port, and exits with status 4, which is Dynoxide's own. Change the port with `DYNOXIDE_PORT` and
`DYNAMODB_ENDPOINT` together. If a
`dynamodb` store or `queue.failed` names an endpoint on another port, the
command warns and names the config key. To change the defaults in a config
file instead, publish it:

```sh
php artisan vendor:publish --tag=dynoxide-config
```

Ctrl+C or SIGTERM stops the server cleanly. With Dynoxide 1.7.0 or later the
server also stops if the artisan process is killed outright. An older binary
(through `DYNOXIDE_BINARY`, say) still works, but the command warns that it
keeps running after a kill. The command's signal tests run on Linux and macOS
only.

## Not for production

This is for tests and local development. Don't point a production app at
Dynoxide, and keep the package in `require-dev`.

## Licence

Dual-licensed under either of [MIT](LICENSE-MIT) or
[Apache 2.0](LICENSE-APACHE), at your option, the same as Dynoxide.
