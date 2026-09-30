<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function count;
use function explode;
use function file;
use function is_array;
use function is_file;

use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

use function rawurldecode;
use function str_contains;

/** The tests the extension recorded for one mutant: which killed it, and whether any passed. */
final readonly class Recorded
{
    private const string SEPARATOR = ' ';

    private function __construct(private TestIds $killers, private TestIds $passed)
    {
    }

    /** What a results file records; a file that is not there records nothing. */
    public static function in(string $results): self
    {
        $lines = is_file($results) ? file($results, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        $killers = TestIds::none();
        $passed = TestIds::none();

        foreach (is_array($lines) ? $lines : [] as $line) {
            [$outcome, $test] = str_contains($line, self::SEPARATOR) ? explode(self::SEPARATOR, $line, 2) : [$line, ''];
            $ended = Outcome::tryFrom($outcome);
            $id = TestId::of(rawurldecode($test));
            $killers = $ended instanceof Outcome && $ended->kills() ? $killers->with($id) : $killers;
            $passed = $ended === Outcome::Passed ? $passed->with($id) : $passed;
        }

        return new self($killers, $passed);
    }

    public function killers(): TestIds
    {
        return $this->killers;
    }

    /** Whether any test ran to an end, passed or not. */
    public function ranAny(): bool
    {
        return count($this->killers) + count($this->passed) > 0;
    }
}
