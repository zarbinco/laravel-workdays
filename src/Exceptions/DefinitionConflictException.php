<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Exceptions;

use RuntimeException;

/** The caller must retrieve current definitions and explicitly resolve its stale edit. */
final class DefinitionConflictException extends RuntimeException {}
