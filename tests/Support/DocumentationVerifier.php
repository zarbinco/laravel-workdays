<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/** Checks authored documentation; executable code is compared without HTML wrappers. */
final class DocumentationVerifier
{
    public const TOPICS = ['README', 'getting-started', 'configuration', 'database', 'business-days', 'working-hours', 'delivery', 'delivery-slots', 'sla', 'recurring-schedules', 'bulk-calculations', 'snapshots', 'laravel-integration', 'api-reference', 'troubleshooting'];

    public const CONTRIBUTORS = ['CONTRIBUTING.md', 'docs/ARCHITECTURE.md', 'FEATURE_PRESERVATION_MATRIX.md', 'NUMERICAL_CHANGELOG.md', 'CHANGELOG.md'];

    private const BIDI = '/[\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return array<string, string> */
    public static function documents(): array
    {
        $documents = [];
        $walk = static function (string $directory) use (&$walk, &$documents): void {
            foreach (new \DirectoryIterator($directory) as $entry) {
                if ($entry->isDot() || $entry->isLink()) {
                    continue;
                }
                if ($entry->isDir()) {
                    if (! in_array($entry->getFilename(), ['vendor', 'node_modules', '.git', '.agents', '.codex', '.phpunit.cache'], true)) {
                        $walk($entry->getPathname());
                    }

                    continue;
                }
                if (strtolower($entry->getExtension()) !== 'md'
                    || preg_match('/^(?:AGENTS|SKILL|CLAUDE|LICENSE|LICENCE|COPYRIGHT|NOTICE)\.md$/i', $entry->getFilename()) === 1) {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen(self::root()) + 1));
                $documents[$relative] = file_get_contents($entry->getPathname());
            }
        };
        $walk(self::root());
        ksort($documents);

        return $documents;
    }

    public static function prohibited(string $text): array
    {
        $term = '(?:phases?(?:[\s_-]*[0-9]+)?|micro[\s_-]*fix|mircofix|refactor(?:ing)?(?:[\s_-]*[0-9]+)?|architecture[\s_-]+refactor|ai(?:[\s_-]+(?:generated|friendly))?|llm|agent[\s_-]+generated|implementation[\s_-]+agent|chat[\s_-]*gpt|codex|copilot|gpt[\s_-]+generated|copy[\s_-]*paste[\s_-]+prompt|independent[\s_-]+chatgpt[\s_-]+review|فاز(?:ها|های)?|میکرو[\s\x{200C}_-]*فیکس|رفکتور|ریفکتور|بازطراحی[\s_-]*[0-9۰-۹٠-٩]+|هوش[\s\x{200C}]+مصنوعی|کدکس|چت[\s\x{200C}‌-]*جی[\s\x{200C}‌-]*پی[\s\x{200C}‌-]*تی)';
        $term = str_replace(['[\s_-]', '[0-9]+'], ['[\s\p{Pd}_]', '\p{Nd}+'], $term);
        preg_match_all('/(?<![\p{L}\p{N}])'.$term.'(?![\p{L}\p{N}])/iu', $text, $matches);

        return $matches[0];
    }

    /** @return array{text: string, exceptions: list<string>} */
    public static function authoredText(string $path, string $text): array
    {
        $exceptions = [];
        if (! in_array($path, self::CONTRIBUTORS, true)) {
            return ['text' => $text, 'exceptions' => []];
        }
        $accept = static function (string $reference) use (&$exceptions): bool {
            if (self::prohibited($reference) === []) {
                return false;
            }
            $candidate = rawurldecode(explode('#', $reference, 2)[0]);
            $candidate = preg_replace('~^(?:\.\./)+~', '', $candidate);
            if (preg_match('~^tests/[A-Za-z0-9_./-]+$~D', $candidate) === 1
                && preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $candidate) !== 1
                && is_file(self::root().'/'.$candidate)) {
                $exceptions[] = $reference;

                return true;
            }
            if (preg_match('/^(?:(?<class>[A-Za-z0-9_]+)::)?(?<method>test_[A-Za-z0-9_]+)$/D', $reference, $match) === 1) {
                foreach (glob(self::root().'/tests/Feature/'.($match['class'] !== '' ? $match['class'] : '*').'.php') as $file) {
                    if (preg_match('/function\s+'.preg_quote($match['method'], '/').'\s*\(/', file_get_contents($file)) === 1) {
                        $exceptions[] = $reference;

                        return true;
                    }
                }
            }

            return false;
        };
        $text = preg_replace_callback('/`([^`\n]+)`/', static fn (array $match): string => $accept($match[1]) ? '`machine-reference`' : $match[0], $text);
        $text = preg_replace_callback('/(?<!!)(\[[^\]\n]+\]\()([^\s)]+)(\))/', static fn (array $match): string => $match[1].($accept($match[2]) ? 'machine-reference' : $match[2]).$match[3], $text);

        return ['text' => $text, 'exceptions' => array_values(array_unique($exceptions))];
    }

    public static function hasBidiControls(string $text): bool
    {
        return preg_match(self::BIDI, $text) === 1;
    }

    /** @return list<array{language: string, code: string}> */
    public static function fences(string $markdown): array
    {
        $fences = [];
        $active = null;
        foreach (explode("\n", self::normalize($markdown)) as $line) {
            if ($active === null && preg_match('/^(`{3,}|~{3,})([^\s`]*)\s*$/', $line, $match) === 1) {
                $active = ['delimiter' => $match[1], 'language' => $match[2], 'code' => ''];
            } elseif ($active !== null && preg_match('/^'.preg_quote($active['delimiter'], '/').'\s*$/', $line) === 1) {
                $fences[] = ['language' => $active['language'], 'code' => $active['code']];
                $active = null;
            } elseif ($active !== null) {
                $active['code'] .= $line."\n";
            }
        }
        if ($active !== null) {
            throw new \RuntimeException('An opening fence is not closed.');
        }

        return $fences;
    }

    /** @return array<string, array{code: string, output: string}> */
    public static function examples(string $markdown): array
    {
        $examples = [];
        preg_match_all('/<!--\s*example:\s*([a-z][a-z0-9-]*)\s*-->(.*?)(?=<!--\s*example:|\z)/s', self::normalize($markdown), $sections, PREG_SET_ORDER);
        foreach ($sections as $section) {
            $fences = self::fences($section[2]);
            if (($fences[0]['language'] ?? null) !== 'php' || ! in_array($fences[1]['language'] ?? null, ['text', 'output'], true)) {
                throw new \RuntimeException('Runnable example ['.$section[1].'] requires PHP and exact output fences.');
            }
            if (isset($examples[$section[1]])) {
                throw new \RuntimeException('Duplicate runnable example identifier ['.$section[1].'].');
            }
            $examples[$section[1]] = ['code' => $fences[0]['code'], 'output' => $fences[1]['code']];
        }

        return $examples;
    }

    public static function render(string $markdown): DOMDocument
    {
        $converter = new GithubFlavoredMarkdownConverter(['html_input' => 'allow', 'allow_unsafe_links' => false,
            'heading_permalink' => ['id_prefix' => '', 'fragment_prefix' => '', 'insert' => 'none', 'apply_id_to_heading' => true]]);
        $converter->getEnvironment()->addExtension(new HeadingPermalinkExtension);
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"><html><body>'.$converter->convert($markdown).'</body></html>', LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }

    /** @return list<string> */
    public static function renderedCode(DOMDocument $document): array
    {
        $texts = [];
        foreach ((new DOMXPath($document))->query('//pre/code') as $code) {
            $texts[] = self::normalize($code->textContent);
        }

        return $texts;
    }

    public static function direction(DOMElement $node): string
    {
        while ($node instanceof DOMElement) {
            if ($node->hasAttribute('dir')) {
                return strtolower($node->getAttribute('dir'));
            }
            $node = $node->parentNode;
        }

        return 'ltr';
    }

    public static function normalize(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }
}
