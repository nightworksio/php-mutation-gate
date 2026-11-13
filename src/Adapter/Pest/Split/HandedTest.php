<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_key_exists;
use function explode;
use function file_get_contents;
use function is_array;
use function is_bool;
use function is_file;
use function json_decode;

use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * How a test's own process, forked from a stream's booted application
 * (`splitStream.bootOnce`), ended the test, as it wrote it to the stream's
 * results after an offset: whether it passed the test, none where it never
 * ended it, and each mutant a child of it failed or ended in, which the
 * stream reads into its kills.
 *
 * @internal
 */
final readonly class HandedTest
{
    /** @param list<array{string, string}> $failed each mutant a child failed or ended in, and the test */
    private function __construct(public bool|NotGiven $passed, public array $failed)
    {
    }

    public static function read(string $results, int $from, string $test): self
    {
        $text = is_file($results) ? (string) file_get_contents($results, offset: $from) : '';
        $passed = NotGiven::value();
        $failed = [];

        foreach (explode("\n", $text) as $line) {
            [$read, $said] = self::line($line);

            if ($read->test !== $test) {
                continue;
            }

            $passed = self::passed($read, $said, $passed);
            $failed = self::ends($read) ? [...$failed, [$read->mutant, $test]] : $failed;
        }

        return new self($passed, $failed);
    }

    /**
     * A line of the results read, and whether it says whether a test passed.
     *
     * @return array{StreamRead, bool}
     */
    private static function line(string $line): array
    {
        $decoded = json_decode($line, associative: true);
        $decoded = is_array($decoded) ? $decoded : [];

        return [StreamRead::of($decoded), array_key_exists('p', $decoded) && is_bool($decoded['p'])];
    }

    /** Whether the test passed, as a line of its process's says, or as an earlier line said. */
    private static function passed(StreamRead $read, bool $said, bool|NotGiven $before): bool|NotGiven
    {
        return $read->kind === StreamLine::PARENT && $said ? $read->passed : $before;
    }

    /** Whether a line says a child failed its mutant, or ended in it. */
    private static function ends(StreamRead $read): bool
    {
        $ended = $read->kind === StreamLine::ENDED || ($read->kind === StreamLine::CHILD && $read->failed);

        return $ended && $read->mutant !== '';
    }
}
