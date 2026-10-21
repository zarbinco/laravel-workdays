<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Configuration;

interface DefinitionSource
{
    /** Open one detached view. Future sources must pin their own read consistency here. */
    public function open(): WorkdayDefinitions;
}
