<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Native;

use function array_flip;
use function array_key_exists;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/**
 * The tests a run of a framework's extension runs: those it was told by id,
 * in the group or matching the filter that judges the unit, and declared in
 * the files it names, where it is told each; every test where it is told
 * none of them; and none for a listing, which runs no test.
 *
 * @internal
 */
final readonly class Selected
{
    /** @param array<array-key, int>|NotGiven $files each by its path on disk, as a key */
    private function __construct(
        private TestIds|NotGiven $tests,
        private Group|NotGiven $group,
        private Filter|NotGiven $filter,
        private array|NotGiven $files,
        private bool $listing,
    ) {
    }

    /**
     * The tests that each of these narrowings selects, where it is given.
     *
     * @param list<string>|NotGiven $files the files the tests are declared in, by their paths on disk
     */
    public static function narrowedTo(
        TestIds|NotGiven $tests,
        Group|NotGiven $group,
        Filter|NotGiven $filter,
        array|NotGiven $files,
    ): self {
        $byPath = $files instanceof NotGiven ? $files : array_flip($files);

        return new self($tests, $group, $filter, $byPath, listing: false);
    }

    /** No test, for a run that lists the suite's tests and runs none. */
    public static function listing(): self
    {
        return new self(NotGiven::value(), NotGiven::value(), NotGiven::value(), NotGiven::value(), listing: true);
    }

    public function selects(Candidate $test): bool
    {
        return ! $this->listing
            && ($this->tests instanceof NotGiven || $this->tests->has($test->id()))
            && ($this->group instanceof NotGiven || $test->isIn($this->group))
            && ($this->filter instanceof NotGiven || $this->filter->selects($test->id()))
            && ($this->files instanceof NotGiven || array_key_exists($test->file(), $this->files));
    }
}
