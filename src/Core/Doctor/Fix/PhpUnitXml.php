<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Fix;

use function array_filter;
use function array_values;
use function implode;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function preg_replace_callback;
use function sprintf;
use function trim;

/**
 * A PHPUnit config as `doctor --fix` changes it (ADR-0083), as text, so
 * every line it leaves alone keeps its formatting: the `memory_limit` an
 * `<ini>` sets under `<php>`, and the directories `<source>` includes.
 *
 * @internal
 */
final readonly class PhpUnitXml
{
    private const string MEMORY = '/<ini\b[^>]*\bname\s*=\s*"memory_limit"[^>]*>/u';

    private const string VALUE = '/\bvalue\s*=\s*"[^"]*"/u';

    /** A `<source>` with its body: the indent of its opening tag, the tag, the body, and its closing tag. */
    private const string SOURCE = '/^([ \t]*)(<source\b[^>]*(?<!\/)>)(.*?)(<\/source>)/msu';

    private const string SOURCE_CLOSED = '/^([ \t]*)<source\s*\/>/mu';

    /** An `<include>`'s body. */
    private const string INCLUDES = '/<include\b[^>]*>(.*?)<\/include>/su';

    private const string INCLUDE_END = '/^([ \t]*)<\/include>/mu';

    private const string ROOT_END = '/^([ \t]*)<\/phpunit>/mu';

    private const string INCLUDED = '/<directory\b[^>]*>\s*%s\/?\s*<\/directory>/u';

    private const string DIRECTORY = '%s<directory>%s</directory>';

    private const string INDENT = '    ';

    private const string NEWLINE = "\n";

    /** The text with the `memory_limit` its `<ini>` sets at this value; as it was where none sets it. */
    public static function memoryLimit(string $text, string $limit): string
    {
        return (string) preg_replace_callback(
            self::MEMORY,
            static fn(array $ini): string => (string) preg_replace(self::VALUE, sprintf('value="%s"', $limit), $ini[0]),
            $text,
        );
    }

    /**
     * The text with `<source>` including each of these directories it does not include already, adding the
     * `<include>`, or `<source>` itself, where it has none.
     *
     * @param list<string> $directories
     */
    public static function sources(string $text, array $directories): string
    {
        $included = preg_match(self::SOURCE, $text, $source) === 1
            && preg_match(self::INCLUDES, $source[3], $include) === 1 ? $include[1] : '';
        $missing = array_values(array_filter(
            $directories,
            static fn(string $directory): bool => preg_match(
                sprintf(self::INCLUDED, preg_quote($directory, '/')),
                $included,
            ) !== 1,
        ));

        return match (true) {
            $missing === [] => $text,
            preg_match(self::SOURCE, $text) === 1 => (string) preg_replace_callback(
                self::SOURCE,
                static fn(array $source): string => sprintf(
                    '%s%s%s%s',
                    $source[1],
                    $source[2],
                    self::included($source[3], $source[1], $missing),
                    $source[4],
                ),
                $text,
                1,
            ),
            preg_match(self::SOURCE_CLOSED, $text) === 1 => (string) preg_replace_callback(
                self::SOURCE_CLOSED,
                static fn(array $closed): string => self::block($closed[1], $missing),
                $text,
                1,
            ),
            default => (string) preg_replace_callback(
                self::ROOT_END,
                static fn(array $end): string => sprintf(
                    '%s%s%s',
                    self::block(sprintf('%s%s', $end[1], self::INDENT), $missing),
                    self::NEWLINE,
                    $end[0],
                ),
                $text,
                1,
            ),
        };
    }

    /**
     * A `<source>`'s body with these directories included, in its `<include>`, or in one added where it has none.
     *
     * @param list<string> $missing
     */
    private static function included(string $body, string $indent, array $missing): string
    {
        if (preg_match(self::INCLUDE_END, $body) === 1) {
            return (string) preg_replace_callback(
                self::INCLUDE_END,
                static fn(array $end): string => sprintf(
                    '%s%s%s',
                    self::directories(sprintf('%s%s', $end[1], self::INDENT), $missing),
                    self::NEWLINE,
                    $end[0],
                ),
                $body,
                1,
            );
        }

        $inner = sprintf('%s%s', $indent, self::INDENT);
        $include = implode(self::NEWLINE, [
            '',
            sprintf('%s<include>', $inner),
            self::directories(sprintf('%s%s', $inner, self::INDENT), $missing),
            sprintf('%s</include>', $inner),
        ]);

        return trim($body) === ''
            ? sprintf('%s%s%s', $include, self::NEWLINE, $indent)
            : sprintf('%s%s', $include, $body);
    }

    /** @param list<string> $missing */
    private static function block(string $indent, array $missing): string
    {
        $inner = sprintf('%s%s', $indent, self::INDENT);

        return implode(self::NEWLINE, [
            sprintf('%s<source>', $indent),
            sprintf('%s<include>', $inner),
            self::directories(sprintf('%s%s', $inner, self::INDENT), $missing),
            sprintf('%s</include>', $inner),
            sprintf('%s</source>', $indent),
        ]);
    }

    /** @param list<string> $missing */
    private static function directories(string $indent, array $missing): string
    {
        $lines = [];

        foreach ($missing as $directory) {
            $lines[] = sprintf(self::DIRECTORY, $indent, $directory);
        }

        return implode(self::NEWLINE, $lines);
    }
}
