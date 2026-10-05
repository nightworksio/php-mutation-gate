<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_map;

use Generator;

use function is_array;

use NightWorksIO\MutationGate\Adapter\Opcache\Uncompiled;
use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

/**
 * Which survivors are their original program, where `equivalence.static`
 * asks (ADR-0013, decisions 10 and 11). Each is its runner's mutant as a
 * static analyser checks it (ADR-0020, decision 9), beside its original as
 * written, or printed as its runner prints its mutants, so it reads only the
 * checkout. A survivor whose file cannot be read, or whose change does not
 * go back onto it, is proven nothing. Where opcache dumps nothing, none is
 * proven, and a warning says so.
 */
final readonly class StaticEquivalence
{
    private const string NO_OPCACHE = 'No mutant was checked for equivalence: opcache is not available.';

    public function __construct(private Adapters $adapters, private Settings $settings)
    {
    }

    /** The survivors of these results proven equivalent: run, proved or carried alike. */
    public function among(UnitResults $results): Equivalents
    {
        return $this->proven($this->mutantsOf($results));
    }

    /** @param iterable<Mutant> $mutants */
    public function proven(iterable $mutants): Equivalents
    {
        if (! $this->settings->ignores()->staticEquivalence()) {
            return Equivalents::none();
        }

        $pairs = [];
        $ids = [];

        foreach ($mutants as $mutant) {
            if ($mutant->status() !== MutantStatus::Survived) {
                continue;
            }

            $pair = $this->paired($mutant);

            if (is_array($pair)) {
                $pairs[] = [$mutant->id()->key(), ...$pair];
                $ids[$mutant->id()->key()] = $mutant->id();
            }
        }

        $proven = $pairs === [] ? [] : $this->adapters->equivalence->proven($pairs);

        return $proven instanceof Uncompiled
            ? new Equivalents(MutantIds::none(), Warnings::of(Warning::that(self::NO_OPCACHE)))
            : new Equivalents(
                MutantIds::of(...array_map(static fn(string $key): MutantId => $ids[$key], $proven)),
                Warnings::none(),
            );
    }

    /** @return array{Contents, Contents}|Missing|CannotJudge a survivor's original and its mutant, or why not */
    private function paired(Mutant $survivor): array|Missing|CannotJudge
    {
        $checkable = $this->adapters->runner->checkable($survivor);

        if (! $checkable instanceof Checkable) {
            return $checkable;
        }

        $original = $checkable->original();
        $written = $original instanceof Contents
            ? $original
            : $this->adapters->project->read($survivor->location()->file());

        return $written instanceof Contents ? [$written, $checkable->mutant()] : $written;
    }

    /** @return Generator<int, Mutant> every mutant each result reports */
    private function mutantsOf(UnitResults $results): Generator
    {
        foreach ($results as $result) {
            yield from $result->mutants();
        }
    }
}
