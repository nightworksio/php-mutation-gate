<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpSpec;

use function in_array;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;

/**
 * The examples a run of the launcher runs: those its examples file lists,
 * in the group or matching the filter that judges the unit, and of the spec
 * files it names, where it is told each; every example where it is told
 * none of them; none for a listing, which runs no example.
 */
final readonly class Selected
{
    /**
     * @param list<string>|NotGiven $examples by id
     * @param list<string>|NotGiven $specs    by their files on disk
     */
    public function __construct(
        private array|NotGiven $examples,
        private Group|NotGiven $group,
        private Filter|NotGiven $filter,
        private array|NotGiven $specs,
        private bool $listing,
    ) {
    }

    public function selects(Example $example): bool
    {
        return ! $this->listing
            && ($this->examples instanceof NotGiven || in_array($example->id(), $this->examples, strict: true))
            && ($this->group instanceof NotGiven || $example->isIn($this->group))
            && ($this->filter instanceof NotGiven || $this->filter->selects(TestId::of($example->id())))
            && ($this->specs instanceof NotGiven || in_array($example->file(), $this->specs, strict: true));
    }
}
