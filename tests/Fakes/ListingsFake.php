<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fakes;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestListing;

/** What a fake runner lists for each list of suites: what it was told, or else each of its groups, holding no test. */
final readonly class ListingsFake
{
    /** @param array<string, TestListing|CannotJudge> $told what it was told to list, by the list of suites joined */
    private function __construct(private Groups|CannotJudge $groups, private array $told)
    {
    }

    public static function of(Groups|CannotJudge $groups): self
    {
        return new self($groups, []);
    }

    /** These listings, listing these tests for these suites. */
    public function in(Suites $suites, TestListing|CannotJudge $listing): self
    {
        $told = $this->told;
        $told[$suites->joined()] = $listing;

        return new self($this->groups, $told);
    }

    public function listing(Suites $suites): TestListing|CannotJudge
    {
        if (array_key_exists($suites->joined(), $this->told)) {
            return $this->told[$suites->joined()];
        }

        return $this->groups instanceof CannotJudge ? $this->groups : self::listed($this->groups);
    }

    /** Each of these groups, holding no test. */
    public static function listed(Groups $groups): TestListing
    {
        $listing = TestListing::none();

        foreach ($groups as $group) {
            $listing = $listing->grouping($group, TestIds::none());
        }

        return $listing;
    }
}
