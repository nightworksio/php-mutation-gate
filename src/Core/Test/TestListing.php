<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

use function array_key_exists;

/**
 * The tests a runner lists for some suites, without running any: every test,
 * and the tests of each group, as PHPUnit's `--list-tests-xml` writes them.
 */
final readonly class TestListing
{
    /** @param array<array-key, TestIds> $groups each group's tests, by its name */
    private function __construct(private TestIds $tests, private array $groups)
    {
    }

    public static function none(): self
    {
        return new self(TestIds::none(), []);
    }

    /** A listing of these tests, in no group yet. */
    public static function of(TestIds $tests): self
    {
        return new self($tests, []);
    }

    /** This listing, with a group of these tests. */
    public function grouping(Group $group, TestIds $tests): self
    {
        $groups = $this->groups;
        $groups[$group->name()] = $tests;

        return new self($this->tests, $groups);
    }

    public function tests(): TestIds
    {
        return $this->tests;
    }

    /** Every group a listed test is in, in the order they were listed. */
    public function groups(): Groups
    {
        $groups = Groups::none();

        foreach ($this->groups as $name => $tests) {
            $groups = $groups->with(Group::named((string) $name));
        }

        return $groups;
    }

    /** The tests of a group; none where the listing lists no such group. */
    public function inGroup(Group $group): TestIds
    {
        return array_key_exists($group->name(), $this->groups) ? $this->groups[$group->name()] : TestIds::none();
    }
}
