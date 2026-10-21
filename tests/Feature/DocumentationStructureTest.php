<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use DOMElement;
use DOMXPath;
use Zarbinco\LaravelWorkdays\Tests\Support\DocumentationVerifier;
use Zarbinco\LaravelWorkdays\Tests\TestCase;

final class DocumentationStructureTest extends TestCase
{
    public function test_short_entry_points_link_both_languages_and_all_task_guides(): void
    {
        $documents = DocumentationVerifier::documents();
        $this->assertArrayHasKey('README.md', $documents);
        $this->assertLessThan(150, substr_count($documents['README.md'], "\n"));
        foreach (['en', 'fa'] as $language) {
            $this->assertStringContainsString('docs/'.$language.'/README.md', $documents['README.md']);
            $hub = $documents['docs/'.$language.'/README.md'];
            $this->assertLessThan(250, substr_count($hub, "\n"));
            foreach (DocumentationVerifier::TOPICS as $topic) {
                $this->assertArrayHasKey('docs/'.$language.'/'.$topic.'.md', $documents);
                if ($topic !== 'README') {
                    $this->assertStringContainsString($topic.'.md', $hub, $language.' navigation omits '.$topic);
                }
            }
        }
    }

    public function test_each_language_page_links_directly_to_its_matching_counterpart(): void
    {
        foreach (DocumentationVerifier::documents() as $path => $text) {
            if (preg_match('~^docs/(en|fa)/([^/]+\.md)$~', $path, $match) === 1) {
                $other = $match[1] === 'en' ? 'fa' : 'en';
                $this->assertStringContainsString('../'.$other.'/'.$match[2], $text, $path.' has no direct counterpart');
            }
        }
    }

    public function test_authored_documentation_has_no_development_or_tool_wording(): void
    {
        foreach (DocumentationVerifier::documents() as $path => $text) {
            $authored = DocumentationVerifier::authoredText($path, $text);
            $this->assertSame([], DocumentationVerifier::prohibited($authored['text']), $path.' contains prohibited authored wording');
        }
        foreach (glob(DocumentationVerifier::root().'/docs/examples/*.php') as $path) {
            foreach (token_get_all(file_get_contents($path)) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $this->assertSame([], DocumentationVerifier::prohibited($token[1]), basename($path).' has a prohibited example comment');
                }
            }
        }
    }

    public function test_lexical_guard_checks_unicode_tokens_without_substring_false_positives(): void
    {
        $this->assertSame([], DocumentationVerifier::prohibited('daily available fail explain() ordinary tutorial steps transaction isolation'));
        foreach (['AI', 'AI_generated', 'AI–generated', 'LLM', 'Phase 02', 'Phase02', 'Phase۰۲', 'micro-fix', 'micro‑fix', 'mircofix', 'Refactor 03', 'ChatGPT', 'Chat GPT', 'Codex', 'Copilot', 'agent-generated', 'فاز', 'فازها', 'میکرو فیکس', 'میکرو‌فیکس', 'ریفکتور', 'بازطراحی ۰۲', 'هوش مصنوعی', 'چت‌جی‌پی‌تی'] as $term) {
            $this->assertNotSame([], DocumentationVerifier::prohibited($term), $term);
        }
        $this->assertSame([], DocumentationVerifier::prohibited('نیم‌فاصله و تقویم شمسی'));
        $sample = '`tests/Fixtures/nonexistent-refactor.json`';
        $this->assertSame($sample, DocumentationVerifier::authoredText('CONTRIBUTING.md', $sample)['text']);
        $sample = '`test_genuine_pre_refactor_semantics_one_snapshot_is_rejected_despite_valid_hashes`';
        $this->assertSame($sample, DocumentationVerifier::authoredText('docs/en/README.md', $sample)['text']);
        $this->assertSame([], DocumentationVerifier::prohibited(DocumentationVerifier::authoredText('CONTRIBUTING.md', $sample)['text']));
    }

    public function test_relative_files_and_heading_anchors_resolve(): void
    {
        $rendered = [];
        foreach (DocumentationVerifier::documents() as $path => $text) {
            $rendered[$path] = DocumentationVerifier::render($text);
        }
        foreach ($rendered as $path => $document) {
            foreach ((new DOMXPath($document))->query('//a[@href] | //img[@src]') as $link) {
                $target = $link->getAttribute($link->tagName === 'img' ? 'src' : 'href');
                $this->assertFalse(DocumentationVerifier::hasBidiControls(rawurldecode($target)), $path.' has directional controls in a URL');
                if ($target === '' || preg_match('~^[a-z][a-z0-9+.-]*:|^//~i', $target) === 1) {
                    continue;
                }
                [$file, $fragment] = array_pad(explode('#', $target, 2), 2, '');
                $file = rawurldecode(explode('?', $file, 2)[0]);
                $resolved = $file === '' ? DocumentationVerifier::root().'/'.$path : realpath(DocumentationVerifier::root().'/'.dirname($path).'/'.$file);
                $this->assertNotFalse($resolved, $path.' has a missing link: '.$target);
                $this->assertTrue(is_file($resolved) || is_dir($resolved), $path.' has a missing target: '.$target);
                if ($fragment === '' || ! str_ends_with(strtolower($resolved), '.md')) {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($resolved, strlen(DocumentationVerifier::root()) + 1));
                $targetDocument = $rendered[$relative] ?? DocumentationVerifier::render(file_get_contents($resolved));
                $ids = [];
                foreach ((new DOMXPath($targetDocument))->query('//*[@id] | //a[@name]') as $heading) {
                    $ids[] = $heading->getAttribute('id') ?: $heading->getAttribute('name');
                }
                $this->assertContains(rawurldecode($fragment), $ids, $path.' has a missing heading: '.$target);
            }
        }
    }

    public function test_fences_preserve_code_and_persian_direction_wrappers_are_balanced(): void
    {
        foreach (DocumentationVerifier::documents() as $path => $text) {
            $fences = DocumentationVerifier::fences($text);
            foreach ($fences as $fence) {
                $this->assertNotSame('', $fence['language'], $path.' has an unlabelled fence');
                $this->assertFalse(DocumentationVerifier::hasBidiControls($fence['code']), $path.' has hidden directional controls in code');
            }
            $document = DocumentationVerifier::render($text);
            $this->assertSame(array_column($fences, 'code'), DocumentationVerifier::renderedCode($document), $path.' changes code text during rendering');
            $withoutFences = preg_replace('/^(`{3,}|~{3,})[^\n]*\n.*?^\1[ \t]*$/ms', '', DocumentationVerifier::normalize($text));
            preg_match_all('/^#{1,6}[ \t]+.+$/m', $withoutFences, $headings);
            $this->assertSame(count($headings[0]), (new DOMXPath($document))->query('//h1 | //h2 | //h3 | //h4 | //h5 | //h6')->length, $path.' loses Markdown headings while rendering');
            foreach ((new DOMXPath($document))->query('//code') as $code) {
                $this->assertFalse(DocumentationVerifier::hasBidiControls($code->textContent), $path.' has hidden directional controls in an identifier');
            }
            if (! str_starts_with($path, 'docs/fa/')) {
                if (str_starts_with($path, 'docs/en/')) {
                    $this->assertSame(0, (new DOMXPath($document))->query('//*[@dir="rtl"]')->length, $path.' must remain LTR');
                }

                continue;
            }
            $stack = [];
            $insideFence = false;
            foreach (explode("\n", DocumentationVerifier::normalize($text)) as $line) {
                if (preg_match('/^(`{3,}|~{3,})/', $line) === 1) {
                    if (! $insideFence) {
                        $this->assertSame('ltr', end($stack), $path.' opens a code fence outside LTR');
                    }
                    $insideFence = ! $insideFence;
                }
                if ($insideFence) {
                    continue;
                }
                if (preg_match('/^<div dir="(rtl|ltr)">\s*$/', $line, $match) === 1) {
                    $stack[] = $match[1];
                } elseif (trim($line) === '</div>') {
                    $this->assertNotEmpty($stack, $path.' closes an unopened wrapper');
                    array_pop($stack);
                }
            }
            $this->assertSame([], $stack, $path.' leaves a direction wrapper open');
            foreach ((new DOMXPath($document))->query('//code') as $code) {
                $this->assertSame('ltr', DocumentationVerifier::direction($code), $path.' renders code RTL');
            }
            foreach ((new DOMXPath($document))->query('//h1 | //h2 | //h3 | //p | //li | //th | //td') as $node) {
                if ($node instanceof DOMElement && preg_match('/[\x{0600}-\x{06FF}]/u', $node->textContent) === 1) {
                    $this->assertSame('rtl', DocumentationVerifier::direction($node), $path.' loses RTL on descriptive content');
                }
            }
            $this->assertGreaterThan(0, (new DOMXPath($document))->query('//*[@dir="rtl"]')->length, $path.' has no RTL base');
        }
    }

    public function test_task_guides_expose_core_capabilities_and_the_detailed_reference(): void
    {
        $coverage = [
            'business-days' => ['calculate(', 'BusinessDayResult', 'skipped_dates', 'isCalendarHoliday(', 'isHoliday(', 'explain(', 'include_start_date', 'max_scan_days'],
            'working-hours' => ['addBusinessMinutes(', 'diffBusinessMinutes(', 'diffBusinessHours(', 'previousBusinessTime(', 'special_working_hours', 'seasonal_working_hours', 'allow_overnight_working_hours', '24:00'],
            'configuration' => ['extends', 'profile_routing', 'resolveProfile(', 'profileFor(', 'extra_working_days', 'jalali', 'hijri'],
            'database' => ['DatabaseDefinitionRepository', 'expectedRevisionId', 'DefinitionConflictException', 'workdays:definitions:import', 'workdays:definitions:export', '--expected-revision', '--dry-run'],
            'delivery' => ['resolveCutoff(', 'planDelivery(', 'reversePlanDelivery(', 'business_days', 'lookback_days'],
            'delivery-slots' => ['deliverySlots(', 'duration_minutes', 'step_minutes', 'max_slots'],
            'sla' => ['evaluateSla(', 'target_minutes', 'pauses', 'ended_at', 'breached'],
            'recurring-schedules' => ['recurringSchedule(', 'jalali', 'month_anchor', 'short_month', 'collision_policy', 'max_shift_days'],
            'bulk-calculations' => ['bulkCalculate(', 'BulkCalculationResult', 'BulkCalculationItemResult', 'on_error', 'prefetch', 'DefinitionSourceException'],
            'snapshots' => ['captureDeliveryPromise(', 'compareDeliveryPromise(', 'DeliveryPromiseSnapshot', 'calculation_schema_version', 'integrity_hash'],
            'laravel-integration' => ['CarbonImmutable', 'WorkdayRule', 'nonWorkingDay(', 'workdayNextBusinessDay'],
            'api-reference' => ['CutoffResult', 'DeliveryPlanResult', 'ReverseDeliveryPlanResult', 'DeliverySlotsResult', 'SlaResult', 'RecurringScheduleResult', 'DefinitionSourceException', 'WorkdayConfigurationException'],
            'troubleshooting' => ['max_scan_days', 'isCalendarHoliday(', 'DefinitionSourceException'],
        ];
        foreach (['en', 'fa'] as $language) {
            foreach ($coverage as $topic => $identifiers) {
                $path = 'docs/'.$language.'/'.$topic.'.md';
                $text = file_get_contents(DocumentationVerifier::root().'/'.$path);
                foreach ($identifiers as $identifier) {
                    $this->assertStringContainsString($identifier, $text, $path.' omits '.$identifier);
                }
                if ($topic !== 'api-reference') {
                    $this->assertStringContainsString('api-reference.md', $text, $path.' has no detailed reference link');
                }
            }
        }
    }

    public function test_every_public_facade_method_and_command_is_discoverable_in_both_languages(): void
    {
        preg_match_all('/@method static [^\n]+? ([A-Za-z][A-Za-z0-9_]*)\(/', file_get_contents(DocumentationVerifier::root().'/src/Facades/Workday.php'), $methods);
        $this->assertNotEmpty($methods[1]);
        foreach (['en', 'fa'] as $language) {
            $corpus = '';
            foreach (glob(DocumentationVerifier::root().'/docs/'.$language.'/*.md') as $path) {
                $corpus .= file_get_contents($path)."\n";
            }
            foreach ($methods[1] as $method) {
                $this->assertStringContainsString($method.'(', $corpus, $language.' has no discoverable public method '.$method);
            }
            foreach (glob(DocumentationVerifier::root().'/src/Commands/*.php') as $command) {
                preg_match('/protected \$signature = \'([^\']+)\'/s', file_get_contents($command), $signature);
                $this->assertArrayHasKey(1, $signature);
                preg_match('/^([^\s]+)/', $signature[1], $name);
                $this->assertStringContainsString($name[1], $corpus, $language.' omits command '.$name[1]);
                preg_match_all('/\{(--[a-z][a-z-]*)/', $signature[1], $options);
                foreach ($options[1] as $option) {
                    $this->assertStringContainsString($option, $corpus, $language.' omits option '.$option);
                }
            }
        }
    }
}
