<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Zarbinco\LaravelWorkdays\Tests\Support\DocumentationVerifier;
use Zarbinco\LaravelWorkdays\Tests\TestCase;

final class DocumentationExamplesTest extends TestCase
{
    /** @return array<string, array{string, string, string}> */
    public static function documentedPrograms(): array
    {
        $cases = [];
        foreach (DocumentationVerifier::documents() as $path => $text) {
            if ($path !== 'README.md' && preg_match('~^docs/(?:en|fa)/~', $path) !== 1) {
                continue;
            }
            foreach (DocumentationVerifier::examples($text) as $id => $example) {
                $cases[$path.' '.$id] = [$path, $id, $example['output']];
            }
        }

        return $cases;
    }

    #[DataProvider('documentedPrograms')]
    public function test_documented_program_produces_its_exact_output(string $path, string $id, string $expected): void
    {
        $this->assertSame('testing', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.testing.database'));
        $this->assertSame('sqlite', config('database.connections.testing.driver'));
        $language = str_starts_with($path, 'docs/fa/') ? 'fa' : 'en';
        $setup = DocumentationVerifier::examples(file_get_contents(DocumentationVerifier::root().'/docs/'.$language.'/getting-started.md'));
        if ($path !== 'README.md' && ! str_ends_with($path, '/database.md') && $id !== 'config-quick-start') {
            $this->assertArrayHasKey('config-quick-start', $setup);
            $this->execute($setup['config-quick-start']['code']);
        }
        $settings = config('workdays');
        $connections = config('database.connections');
        $example = DocumentationVerifier::examples(file_get_contents(DocumentationVerifier::root().'/'.$path))[$id];
        $this->assertSame($expected, DocumentationVerifier::normalize($this->execute($example['code'])), $path.' '.$id);
        if (str_ends_with($path, '/database.md')) {
            $this->assertSame($settings, config('workdays'));
            $this->assertSame($connections, config('database.connections'));
        }
    }

    public function test_paired_examples_and_outputs_are_identical_and_cover_the_reader_tasks(): void
    {
        $examplesByPage = [];
        foreach (DocumentationVerifier::TOPICS as $topic) {
            $english = DocumentationVerifier::examples(file_get_contents(DocumentationVerifier::root().'/docs/en/'.$topic.'.md'));
            $persian = DocumentationVerifier::examples(file_get_contents(DocumentationVerifier::root().'/docs/fa/'.$topic.'.md'));
            ksort($english);
            ksort($persian);
            $this->assertSame($english, $persian, $topic.' has different programs or expected output between languages');
            $examplesByPage[$topic] = $english;
        }
        foreach (['getting-started', 'business-days', 'working-hours', 'database', 'delivery', 'delivery-slots', 'sla', 'recurring-schedules', 'bulk-calculations', 'snapshots'] as $topic) {
            $this->assertNotEmpty($examplesByPage[$topic], $topic.' has no verified runnable program');
        }
    }

    public function test_complete_working_time_verification_runs_for_config_and_private_database(): void
    {
        $verify = require DocumentationVerifier::root().'/docs/examples/working-time-verification.php';
        $workdays = config('workdays');
        $connections = config('database.connections');
        foreach (['config', 'database'] as $source) {
            $result = $verify($source);
            $this->assertSame($source, $result['source']);
            $this->assertNotEmpty($result['checks']);
            foreach ($result['checks'] as $name => $passed) {
                $this->assertTrue($passed, $source.' '.$name);
            }
            $this->assertSame($workdays, config('workdays'));
            $this->assertSame($connections, config('database.connections'));
        }
    }

    private function execute(string $code): string
    {
        $level = ob_get_level();
        ob_start();
        try {
            (static function (string $program): void {
                eval(preg_replace('/^<\?php\s*/', '', $program));
            })($code);

            return ob_get_contents();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }
}
