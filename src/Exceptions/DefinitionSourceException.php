<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Exceptions;

use RuntimeException;

/** A selected source could not open a usable definition view; never an item-domain error. */
final class DefinitionSourceException extends RuntimeException {}
