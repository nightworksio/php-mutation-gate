<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use function count;

use DateTimeImmutable;
use NightWorksIO\MutationGate\Core\Config\Expiry;
use NightWorksIO\MutationGate\Core\Config\Ignored;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Time\Day;

use function sprintf;

/**
 * The config's ignores, applied at verdict time (ADR-0008, decision 4): an
 * entry that has not expired leaves each survivor and uncovered mutant it
 * names out of the score, with its reason. An expired one stops applying and
 * is named with the day it expired, one that expires soon is named in
 * advance, one that names no mutant in a run that judged every unit
 * fails the run, and one that leaves out only mutants proven equivalent is
 * named as one that can go.
 */
final readonly class Ignoring
{
    private const string EXPIRED = 'The ignore of %s expired on %s, so its mutants count again.';

    private const string EXPIRING = 'The ignore of %s expires on %s.';

    private const string STALE
        = 'The ignore of %s names no mutant it could leave out, in a run that judged every unit: remove it.';

    /**
     * @param list<Ignored>             $applying the entries that still apply
     * @param list<array{Ignored, Day}> $expired  the entries whose day has passed, with the day
     * @param list<array{Ignored, Day}> $expiring the entries that still apply and end within the notice, with the day
     */
    private function __construct(private array $applying, private array $expired, private array $expiring)
    {
    }

    public static function none(): self
    {
        return new self([], [], []);
    }

    /** @param Listed<Ignored> $entries */
    public static function of(Listed $entries, DateTimeImmutable $now): self
    {
        $applying = [];
        $expired = [];
        $expiring = [];

        foreach ($entries as $entry) {
            $expires = $entry->expires();

            if (! $expires instanceof Day) {
                $applying[] = $entry;

                continue;
            }

            $expiry = Expiry::of($expires, $now);

            if ($expiry === Expiry::Expired) {
                $expired[] = [$entry, $expires];

                continue;
            }

            $applying[] = $entry;

            if ($expiry === Expiry::Expiring) {
                $expiring[] = [$entry, $expires];
            }
        }

        return new self($applying, $expired, $expiring);
    }

    /**
     * The mutant, left out with the reason of the first entry that names it
     * and leaves it out: one that asks for a test, or one unjudged because its
     * file was loaded before it was in place, by an entry that names its id.
     */
    public function judged(JudgedMutant $mutant): JudgedMutant
    {
        foreach ($this->applying as $entry) {
            $ignored = $entry->matches($mutant->mutant())
                ? $mutant->ignoredBecause(Reason::that($entry->reason()), $entry->namesOne())
                : $mutant;

            if ($ignored->judgement() === MutantJudgement::Ignored) {
                return $ignored;
            }
        }

        return $mutant;
    }

    /** Each entry that has expired, with the day, and each that expires within the notice. */
    public function warnings(): Warnings
    {
        $warnings = Warnings::none();

        foreach ($this->expired as [$entry, $day]) {
            $warnings = $warnings->with(Warning::that(sprintf(self::EXPIRED, $entry->named(), $day->value())));
        }

        foreach ($this->expiring as [$entry, $day]) {
            $warnings = $warnings->with(Warning::that(sprintf(self::EXPIRING, $entry->named(), $day->value())));
        }

        return $warnings;
    }

    /**
     * A notice of each entry that still applies and leaves out only
     * survivors proven equivalent (ADR-0013, decision 12).
     */
    public function redundant(TreeVerdicts $verdicts, MutantIds $proven): Warnings
    {
        return RedundantIgnores::among($this->applying, $verdicts, $proven);
    }

    /**
     * Each entry that still applies but names no mutant it could leave out,
     * a survivor, an uncovered mutant, or one an ignore left out or a proof
     * found equivalent (ADR-0013, decision 12); none where a unit has no
     * result, as the failures of the units that did not run say, or a mutant
     * is unjudged, since either could hold the mutant an entry names.
     */
    public function stale(TreeVerdicts $verdicts, Failures $unrun): Failures
    {
        $failures = Failures::none();
        $judgedEveryMutant = count($unrun) === 0
            && $verdicts->mutants()->counts()->number(MutantJudgement::Unjudged) === 0;

        foreach ($judgedEveryMutant ? $this->applying : [] as $entry) {
            $failures = $this->names($entry, $verdicts)
                ? $failures
                : $failures->with(Failure::that(sprintf(self::STALE, $entry->named())));
        }

        return $failures;
    }

    private function names(Ignored $entry, TreeVerdicts $verdicts): bool
    {
        foreach ($verdicts as $verdict) {
            foreach ($verdict->mutants() as $judged) {
                $named = $judged instanceof JudgedMutant
                    && $this->leavesOut($judged->judgement())
                    && $entry->matches($judged->mutant());

                if ($named) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Whether an entry naming a mutant judged so has work to do: it leaves the mutant out, or did. */
    private function leavesOut(MutantJudgement $judgement): bool
    {
        return $judgement->asksForATest()
            || $judgement === MutantJudgement::Ignored
            || $judgement === MutantJudgement::Equivalent;
    }
}
