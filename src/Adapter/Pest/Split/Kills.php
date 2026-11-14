<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_key_exists;

/**
 * What a split stream knows of its kills (ADR-0117): whether the parent
 * passed each test it ended, and each mutant a child failed or ended in a
 * test the parent passed, or that waits for the parent to end the test.
 *
 * @internal
 */
final class Kills
{
    /** @var array<string, true> the mutants a child killed in a test the parent passed, by id */
    private array $killed = [];

    /** @var array<string, bool> whether the parent passed each test it ended, by the test's id */
    private array $passed = [];

    /** @var array<string, list<string>> the mutants a child failed, by the test the parent is still in */
    private array $pending = [];

    /**
     * Children failed or ended in these tests: each mutant is killed where the
     * parent passed its test, or once the parent does.
     *
     * @param list<array{string, string}> $failed each mutant, and the test
     */
    public function failedIn(array $failed): void
    {
        foreach ($failed as [$mutant, $test]) {
            if (! array_key_exists($test, $this->passed)) {
                $this->pending[$test][] = $mutant;

                continue;
            }

            if ($this->passed[$test]) {
                $this->killed[$mutant] = true;
            }
        }
    }

    /** The parent ended this test, passed or not: each mutant a child failed in it is killed where it passed. */
    public function ended(string $test, bool $passed): void
    {
        $this->passed[$test] = $passed;
        $waiting = array_key_exists($test, $this->pending) ? $this->pending[$test] : [];
        unset($this->pending[$test]);

        foreach ($waiting as $mutant) {
            $this->failedIn([[$mutant, $test]]);
        }
    }

    public function has(string $mutant): bool
    {
        return array_key_exists($mutant, $this->killed);
    }
}
