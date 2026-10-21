<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Exceptions;

use RuntimeException;

/** A valid calendar has insufficient availability within the owning operation's bound. */
final class WorkingTimeSearchException extends RuntimeException {}
