<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Exception;

use RuntimeException;

/**
 * The app's config asks for something Dynoxide can't set up. The message
 * names the config keys involved.
 */
final class ConfigException extends RuntimeException
{
}
