<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function file;
use function glob;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;

use NightWorksIO\MutationGate\Core\Coverage\StreamedSuite;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;
use function str_starts_with;

/**
 * How the tests ran in a shard's stream parents (ADR-0135), from every
 * `recorded-*.jsonl` of a directory: each test's end, each line pcov
 * reported in each file of the project, and whether every stream ended
 * each of its tests, which a stream that stopped short leaves unsaid.
 *
 * @internal
 */
final readonly class RecordedSuite
{
    private const string RECORDED = '%s/recorded-*.jsonl';

    /** The suite recorded in a directory; none where its streams recorded nothing. */
    public static function in(string $directory, Root $root): StreamedSuite|NotGiven
    {
        $files = glob(sprintf(self::RECORDED, $directory)) ?: [];

        if ($files === []) {
            return NotGiven::value();
        }

        $suite = StreamedSuite::none();

        foreach ($files as $file) {
            $suite = $suite->and(self::of(file($file) ?: [], $root));
        }

        return $suite;
    }

    /** @param list<string> $texts one stream's lines */
    private static function of(array $texts, Root $root): StreamedSuite
    {
        $finished = false;
        $tests = [];
        $reported = [];

        foreach ($texts as $text) {
            $line = json_decode($text, true);
            $kind = is_array($line) ? ($line[StreamLine::KIND] ?? '') : '';

            if ($kind === StreamLine::FINISHED && is_array($line)) {
                $finished = true;
                $reported = self::inside($line['l'] ?? null, $root);
            }

            $ended = $kind === StreamLine::COVERED && is_array($line) ? self::ended($line) : NotGiven::value();

            if (! $ended instanceof NotGiven && is_array($line) && is_string($line['t'] ?? null)) {
                $tests[$line['t']] = $ended;
            }
        }

        return StreamedSuite::of($finished, $tests, $reported);
    }

    /**
     * @param  array<mixed>                                                               $line
     * @return array{passed: bool, troubled: bool, own: bool, seconds: float}|NotGiven
     */
    private static function ended(array $line): array|NotGiven
    {
        [$passed, $troubled, $own, $seconds] = [$line['p'] ?? null, $line['i'] ?? null, $line['o'] ?? null, $line['s'] ?? null];

        return is_bool($passed) && is_bool($troubled) && is_bool($own) && (is_float($seconds) || is_int($seconds))
            ? ['passed' => $passed, 'troubled' => $troubled, 'own' => $own, 'seconds' => (float) $seconds]
            : NotGiven::value();
    }

    /** @return array<string, list<int>> each file of the project's lines, by its path from the root */
    private static function inside(mixed $files, Root $root): array
    {
        $inside = [];

        foreach (is_array($files) ? $files : [] as $file => $lines) {
            if (! is_string($file) || ! str_starts_with($file, $root->value()) || ! is_array($lines)) {
                continue;
            }

            $relative = $root->relative($file)->value();
            $inside[$relative] = [];

            foreach ($lines as $number) {
                if (is_int($number) && $number > 0) {
                    $inside[$relative][] = $number;
                }
            }
        }

        return $inside;
    }
}
