<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use Zarbinco\LaravelWorkdays\Tests\TestCase;

final class DocumentationStructureTest extends TestCase
{
    public function test_bilingual_documentation_files_exist(): void
    {
        $this->assertFileExists($this->rootPath('README.md'));
        $this->assertFileExists($this->rootPath('docs/en/README.md'));
        $this->assertFileExists($this->rootPath('docs/fa/README.md'));
    }

    public function test_main_readme_links_to_bilingual_documents(): void
    {
        $readme = $this->fileContents('README.md');

        $this->assertStringContainsString('English Document', $readme);
        $this->assertStringContainsString('Persian Document', $readme);
        $this->assertStringContainsString('[English Document](docs/en/README.md)', $readme);
        $this->assertStringContainsString('[Persian Document](docs/fa/README.md)', $readme);
    }

    public function test_main_readme_stays_short_and_non_technical(): void
    {
        $readme = $this->fileContents('README.md');

        $this->assertStringNotContainsString('Config Reference', $readme);
        $this->assertStringNotContainsString('Public API Reference', $readme);
        $this->assertStringNotContainsString('Business Hours And Half-Days', $readme);
        $this->assertStringNotContainsString('Validation Rules', $readme);
    }

    public function test_main_readme_has_no_persian_description(): void
    {
        $readme = $this->fileContents('README.md');

        $this->assertSame(0, preg_match('/[\x{0600}-\x{06FF}]/u', $readme));
    }

    public function test_detailed_documents_cross_link_each_other(): void
    {
        $english = $this->fileContents('docs/en/README.md');
        $persian = $this->fileContents('docs/fa/README.md');

        $this->assertStringContainsString('Persian document:', $english);
        $this->assertStringContainsString('../fa/README.md', $english);
        $this->assertStringContainsString('English document:', $persian);
        $this->assertStringContainsString('../en/README.md', $persian);
    }

    public function test_iran_1405_dataset_is_documented_as_optional_import_only(): void
    {
        $english = $this->fileContents('docs/en/README.md');
        $persian = $this->fileContents('docs/fa/README.md');

        $this->assertStringContainsString('Iran 1405 is optional/import-only', $english);
        $this->assertStringContainsString('not bundled with the package', $english);
        $this->assertStringContainsString('not imported automatically', $english);
        $this->assertStringContainsString('not enabled by default', $english);
        $this->assertStringContainsString('--calendar-path=/absolute/path/to/calendars/iran', $english);

        $this->assertStringContainsString('۱۴۰۵', $persian);
        $this->assertStringContainsString('اختیاری و import-only', $persian);
        $this->assertStringContainsString('پکیج دیتاست رسمی ۱۴۰۵ را bundle نمی‌کند', $persian);
        $this->assertStringContainsString('پکیج خودش ۱۴۰۵ را وارد دیتابیس نمی‌کند', $persian);
        $this->assertStringContainsString('--calendar-path=/absolute/path/to/calendars/iran', $persian);
    }

    public function test_persian_document_does_not_contain_placeholder_text(): void
    {
        $persian = $this->fileContents('docs/fa/README.md');

        $this->assertStringNotContainsString('TODO', $persian);
        $this->assertStringNotContainsString('TBD', $persian);
        $this->assertStringNotContainsString('PLACEHOLDER', $persian);
        $this->assertStringNotContainsString('lorem ipsum', strtolower($persian));
    }

    private function rootPath(string $path): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    private function fileContents(string $path): string
    {
        return file_get_contents($this->rootPath($path)) ?: '';
    }
}
