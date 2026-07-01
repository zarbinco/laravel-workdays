<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use Zarbinco\LaravelWorkdays\Tests\TestCase;

final class ComposerDependencyTest extends TestCase
{
    public function test_composer_requires_illuminate_support(): void
    {
        $this->assertSame(
            '^12.0|^13.0',
            $this->composerRequire()['illuminate/support'] ?? null,
        );
    }

    public function test_composer_requires_illuminate_console(): void
    {
        $this->assertSame(
            '^12.0|^13.0',
            $this->composerRequire()['illuminate/console'] ?? null,
        );
    }

    public function test_composer_requires_illuminate_database(): void
    {
        $this->assertSame(
            '^12.0|^13.0',
            $this->composerRequire()['illuminate/database'] ?? null,
        );
    }

    public function test_composer_requires_illuminate_filesystem(): void
    {
        $this->assertSame(
            '^12.0|^13.0',
            $this->composerRequire()['illuminate/filesystem'] ?? null,
        );
    }

    public function test_composer_requires_testbench_for_supported_laravel_versions(): void
    {
        $this->assertSame(
            '^10.0|^11.0',
            $this->composerRequireDev()['orchestra/testbench'] ?? null,
        );
    }

    public function test_composer_declares_mit_license(): void
    {
        $this->assertSame('MIT', $this->composer()['license'] ?? null);
    }

    public function test_composer_declares_package_keywords(): void
    {
        $keywords = $this->composer()['keywords'] ?? [];

        $this->assertIsArray($keywords);
        $this->assertContains('laravel', $keywords);
        $this->assertContains('workdays', $keywords);
        $this->assertContains('business-days', $keywords);
        $this->assertContains('holidays', $keywords);
        $this->assertContains('jalali', $keywords);
        $this->assertContains('hijri', $keywords);
        $this->assertContains('iran', $keywords);
        $this->assertContains('carbon', $keywords);
    }

    /**
     * @return array<string, string>
     */
    private function composerRequire(): array
    {
        $require = $this->composer()['require'] ?? [];

        $this->assertIsArray($require);

        return $require;
    }

    /**
     * @return array<string, string>
     */
    private function composerRequireDev(): array
    {
        $requireDev = $this->composer()['require-dev'] ?? [];

        $this->assertIsArray($requireDev);

        return $requireDev;
    }

    /**
     * @return array<string, mixed>
     */
    private function composer(): array
    {
        $composer = json_decode(
            file_get_contents(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'composer.json') ?: '{}',
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertIsArray($composer);

        return $composer;
    }
}
