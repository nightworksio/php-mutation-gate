<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use function array_key_exists;
use function array_map;
use function array_values;
use function count;
use function implode;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Proof\Inputs;
use NightWorksIO\MutationGate\Core\Proof\NewestProofs;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Uncommitted;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Unit\Units;

use function sprintf;

/**
 * What changed since each commit the carried results of a run were
 * established at, read once for each commit however many results share it
 * (ADR-0008, decision 1): what the change reaches of their kills, or why git
 * cannot say what changed.
 */
final readonly class ChangesSince
{
    private const string UNREAD = 'What changed since %s was not read.';

    private const string NOT_CARRIED = 'No kill proved at %s carries for a unit the budget never started. %s';

    /** @param array<string, ChangeReach|CannotTell> $since by the commit's name */
    private function __construct(private array $since)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * The commits whose change a verdict reads: those the newest results of
     * these units, established at another base and holding a kill, were
     * established at, each once.
     *
     * @return list<Revision>
     */
    public static function commitsOf(Units $units, NewestProofs $newest, Digest $base): array
    {
        $commits = [];

        foreach ($units as $unit) {
            $proof = $newest->of($unit->path());
            $commit = $proof instanceof Proof ? self::commitOf($proof, $base) : Uncommitted::tree();
            $commits += $commit instanceof Revision ? [$commit->name() => $commit] : [];
        }

        return array_values($commits);
    }

    /** These changes, and what changed since one more commit. */
    public function with(Revision $commit, ChangeReach|CannotTell $since): self
    {
        $with = $this->since;
        $with[$commit->name()] = $since;

        return new self($with);
    }

    /** What changed since a commit reaches, or why git cannot say. */
    public function at(Revision $commit): ChangeReach|CannotTell
    {
        return array_key_exists($commit->name(), $this->since)
            ? $this->since[$commit->name()]
            : CannotTell::because(sprintf(self::UNREAD, $commit->name()));
    }

    /** Why the kills proved at each commit git cannot read, or whose change reaches every kill, do not carry. */
    public function warnings(): Warnings
    {
        $warnings = Warnings::none();

        foreach ($this->since as $commit => $since) {
            $why = $since instanceof CannotTell ? $since->why() : $this->reasons($since);
            $said = Warning::that(sprintf(self::NOT_CARRIED, $commit, $why));
            $warnings = $why === '' ? $warnings : $warnings->with($said);
        }

        return $warnings;
    }

    /** The commit a result was established at, where it was at another base and holds a kill. */
    private static function commitOf(Proof $proof, Digest $base): Revision|Uncommitted
    {
        $inputs = $proof->inputs();
        $kills = count($proof->kills()) + self::killed($proof);

        return $inputs instanceof Inputs && $kills > 0 && $proof->run()->base()->value() !== $base->value()
            ? $inputs->commit()
            : Uncommitted::tree();
    }

    /** Why a change reaches every kill, in one line; nothing where it follows each by name. */
    private function reasons(ChangeReach $since): string
    {
        return implode(' ', array_map(static fn(Reason $reason): string => $reason->text(), [...$since->everything()]));
    }

    private static function killed(Proof $proof): int
    {
        $killed = 0;

        foreach ($proof->reported() as $mutant) {
            $killed += $mutant->status() === MutantStatus::Killed ? 1 : 0;
        }

        return $killed;
    }
}
