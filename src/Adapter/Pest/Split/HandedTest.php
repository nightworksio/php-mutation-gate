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
    private function __construct(public HandedEnd $end, public array $failed, private string $test)
    {
    }

    public static function read(string $results, int $from, string $test): self
    {
        $text = is_file($results) ? (string) file_get_contents($results, offset: $from) : '';
        $end = HandedEnd::Unended;
        $failed = [];

        foreach (explode("\n", $text) as $line) {
            [$read, $said] = self::line($line);

            if ($read->test !== $test) {
                continue;
            }

            $end = self::endOf($read, $said, $end);
            $failed = self::ends($read) ? [...$failed, [$read->mutant, $test]] : $failed;
        }

        return new self($end, $failed, $test);
    }

    /**
     * The kills read into these, and how the test ended: a fallback written
     * to the stream's results where its process never ended it.
     */
    public function into(Kills $kills, StreamPlan $plan): void
    {
        $kills->failedIn($this->failed);

        match ($this->end) {
            HandedEnd::Unended => $plan->recorded(StreamLine::fallback($this->test)),
            HandedEnd::Passed, HandedEnd::Failed => $kills->ended($this->test, $this->end === HandedEnd::Passed),
        };
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

    /** How the test ended, as a line of its process's says, or as an earlier line said. */
    private static function endOf(StreamRead $read, bool $said, HandedEnd $before): HandedEnd
    {
        return $read->kind === StreamLine::PARENT && $said ? HandedEnd::of($read->passed) : $before;
    }

    /** Whether a line says a child failed its mutant, or ended in it. */
    private static function ends(StreamRead $read): bool
    {
        $ended = $read->kind === StreamLine::ENDED || ($read->kind === StreamLine::CHILD && $read->failed);

        return $ended && $read->mutant !== '';
    }
}
