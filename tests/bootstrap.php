<?php

declare(strict_types=1);

// The tests must reach Dynoxide and nothing else. With no AWS credentials,
// profile or endpoint in the environment and the instance metadata lookup
// off, a client that missed Dynoxide fails at once instead of reaching AWS
// with whatever credentials this machine has.
foreach (array_keys(getenv()) as $name) {
    if (str_starts_with((string) $name, 'AWS_') || $name === 'DYNAMODB_ENDPOINT') {
        putenv((string) $name);
        unset($_ENV[$name], $_SERVER[$name]);
    }
}
$nowhere = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dynoxide-laravel-no-aws-config';
foreach (['AWS_EC2_METADATA_DISABLED' => 'true', 'AWS_SHARED_CREDENTIALS_FILE' => $nowhere, 'AWS_CONFIG_FILE' => $nowhere] as $name => $value) {
    putenv("{$name}={$value}");
    $_ENV[$name] = $_SERVER[$name] = $value;
}

// The environment before any app has loaded a .env file into it, for the
// fixture suites to start from.
define('DYNOXIDE_LARAVEL_TEST_ENVIRONMENT', getenv());

// A fixture suite run to see what the trait does with AWS variables an app
// sets gets them here, after the clean-up, as JSON in FIXTURE_AWS.
$fixtureAws = getenv('FIXTURE_AWS');
if (is_string($fixtureAws) && $fixtureAws !== '') {
    foreach (json_decode($fixtureAws, true, 2, JSON_THROW_ON_ERROR) as $name => $value) {
        putenv("{$name}={$value}");
        $_ENV[$name] = $_SERVER[$name] = $value;
    }
}

require __DIR__ . '/../vendor/autoload.php';
