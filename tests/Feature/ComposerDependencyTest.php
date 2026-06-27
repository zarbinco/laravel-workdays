<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use Zarbinco\LaravelWorkdays\Tests\TestCase;

final class ComposerDependencyTest extends TestCase
{
    public function test_composer_requires_illuminate_console(): void
    {
        $this->assertSame(
            '^10.0|^11.0|^12.0|^13.0',
            $this->composerRequire()['illuminate/console'] ?? null,
        );
    }

    public function test_composer_requires_illuminate_database(): void
    {
        $this->assertSame(
            '^10.0|^11.0|^12.0|^13.0',
            $this->composerRequire()['illuminate/database'] ?? null,
        );
    }

    public function test_composer_requires_illuminate_filesystem(): void
    {
        $this->assertSame(
            '^10.0|^11.0|^12.0|^13.0',
            $this->composerRequire()['illuminate/filesystem'] ?? null,
        );
    }

    /**
     * @return array<string, string>
     */
    private function composerRequire(): array
    {
        $composer = json_decode(
            file_get_contents(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'composer.json') ?: '{}',
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $require = $composer['require'] ?? [];

        $this->assertIsArray($require);

        return $require;
    }
}
