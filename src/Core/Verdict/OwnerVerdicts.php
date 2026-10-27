<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use function array_filter;
use function array_key_exists;
use function array_map;
use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Owner\CodeOwners;
use NightWorksIO\MutationGate\Core\Owner\OwnerGroup;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;

use function strcmp;

use Traversable;

use function usort;

/**
 * Every owner group a run's mutants belong to, each judged over the
 * mutants of the files it owns, so each mutant is in exactly one and the
 * groups' counts add up to the project's (ADR-0022). The groups are in the
 * order of their names, and the unowned one last. A project without a
 * CODEOWNERS file has none.
 *
 * @implements IteratorAggregate<int, OwnerVerdict>
 */
final readonly class OwnerVerdicts implements Countable, IteratorAggregate
{
    /**
     * @param list<OwnerVerdict>        $verdicts
     * @param array<string, OwnerGroup> $groups   the group of each file the mutants are in, by its path
     */
    private function __construct(private bool $grouped, private array $verdicts, private array $groups)
    {
    }

    public static function none(): self
    {
        return new self(grouped: false, verdicts: [], groups: []);
    }

    /** Each group of these trees' mutants, by the CODEOWNERS rule of each one's file. */
    public static function of(TreeVerdicts $trees, CodeOwners $owners, Uncovered $uncovered): self
    {
        if (! $owners->wasRead()) {
            return self::none();
        }

        $mutants = $trees->mutants();
        $files = [];

        foreach ($mutants as $judged) {
            $files[$judged->mutant()->location()->file()->value()] = $judged->mutant()->location()->file();
        }

        $byFile = array_map($owners->groupOf(...), $files);
        $groups = [];
        $owned = [];

        foreach ($byFile as $file => $group) {
            $groups[$group->name()] = $group;
            $owned[$group->name()][] = $files[$file];
        }

        $verdicts = [];

        foreach ($groups as $name => $group) {
            $verdicts[] = OwnerVerdict::judged($group, $mutants->inFiles(Paths::of(...$owned[$name])), $uncovered);
        }

        usort($verdicts, self::ordered(...));

        return new self(grouped: true, verdicts: $verdicts, groups: $byFile);
    }

    /** The groups a change touched, each with a mutant on a line it added or modified. */
    public function touched(): self
    {
        return new self(
            $this->grouped,
            array_values(array_filter($this->verdicts, static fn(OwnerVerdict $group): bool => $group->isTouched())),
            $this->groups,
        );
    }

    /** The group a file of the mutants belongs to; unowned for any other file. */
    public function groupOf(Path $file): OwnerGroup
    {
        return array_key_exists($file->value(), $this->groups) ? $this->groups[$file->value()] : OwnerGroup::unowned();
    }

    /** Whether the run grouped its mutants by owner: whether it read a CODEOWNERS file. */
    public function areGrouped(): bool
    {
        return $this->grouped;
    }

    public function count(): int
    {
        return count($this->verdicts);
    }

    /** @return Traversable<int, OwnerVerdict> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->verdicts);
    }

    /** By name, the unowned group last. */
    private static function ordered(OwnerVerdict $one, OwnerVerdict $other): int
    {
        $unowned = (int) $one->group()->isUnowned() - (int) $other->group()->isUnowned();

        return $unowned === 0 ? strcmp($one->group()->name(), $other->group()->name()) : $unowned;
    }
}
