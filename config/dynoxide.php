<?php

return [

    /*
    | The port `php artisan dynoxide:serve` listens on. 8800 keeps clear of
    | `php artisan serve` (8000) and Vite (5173). Point DYNAMODB_ENDPOINT at
    | the same port. The command checks the value, so a typo is an error
    | rather than another port.
    */
    'port' => env('DYNOXIDE_PORT', 8800),

    /*
    | The address `php artisan dynoxide:serve` binds to.
    */
    'host' => env('DYNOXIDE_HOST', '127.0.0.1'),

    /*
    | A database file for `php artisan dynoxide:serve`, so data survives a
    | restart. Leave it empty to keep everything in memory.
    */
    'db_path' => env('DYNOXIDE_DB_PATH'),

    /*
    | How many seconds `php artisan dynoxide:serve` waits for Dynoxide to be
    | ready. A first start after a download can take longer while the system
    | checks the new binary.
    */
    'start_timeout' => env('DYNOXIDE_START_TIMEOUT', 10),

];
