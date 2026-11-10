<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function explode;
use function file_get_contents;
use function is_array;
use function is_bool;
use function is_file;
use function is_string;
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
    private function __construct(public ?bool $passed, public array $failed)
    {
    }

    public static function read(string $results, int $from, string $test): self
    {
        $text = is_file($results) ? (string) file_get_contents($results, offset: $from) : '';
        $passed = null;
        $failed = [];

        foreach (explode("\n", $text) as $line) {
            $read = json_decode($line, associative: true);

            if (! is_array($read) || ($read['t'] ?? null) !== $test) {
                continue;
            }

            $kind = $read[StreamLine::KIND] ?? '';
            $mutant = $read['m'] ?? null;

            if ($kind === StreamLine::PARENT && is_bool($read['p'] ?? null)) {
                $passed = $read['p'];
            }

            $ended = $kind === StreamLine::ENDED || ($kind === StreamLine::CHILD && ($read['f'] ?? false) === true);

            if ($ended && is_string($mutant)) {
                $failed[] = [$mutant, $test];
            }
        }

        return new self($passed, $failed);
    }
}
