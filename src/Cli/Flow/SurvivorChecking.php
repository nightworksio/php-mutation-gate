<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_key_exists;
use function array_replace;

use NightWorksIO\MutationGate\Core\Analysis\AnalyserHistory;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\Analysis\MutantCheck;
use NightWorksIO\MutationGate\Core\Analysis\NoAnalyser;
use NightWorksIO\MutationGate\Core\Analysis\OutOfScope;
use NightWorksIO\MutationGate\Core\Analysis\Rejection;
use NightWorksIO\MutationGate\Core\Analysis\SurvivorChecks;
use NightWorksIO\MutationGate\Core\Analysis\Unchecked;
use NightWorksIO\MutationGate\Core\Analysis\UncheckedSurvivor;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Time\Deadline;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Port\StaticChecker;
use Psr\Clock\ClockInterface;

/**
 * Static analysis's check of a shard's survivors, after their tests
 * (ADR-0020, decisions 9, 11 and 13). The analyser runs once over the
 * original files, then checks each survivor that did not prove flaky, in
 * place of its file, as its runner gives it. A survivor with an error its
 * original does not have is killed by static analysis. One the analyser
 * cannot check, or the time budget has no room for, stays a survivor, and
 * the checks say why. A survivor printed as its runner prints its mutants
 * is judged against its original printed the same way, which must first
 * analyse as the file itself does. The checks record their time alone
 * (ADR-0020, decision 11).
 */
final readonly class SurvivorChecking
{
    public function __construct(
        private Adapters $adapters,
        private ClockInterface $clock,
        private Deadline|Unlimited $deadline,
    ) {
    }

    /** These mutants, each survivor the analyser rejects killed by static analysis, with what the checks came to. */
    public function checked(Mutants $mutants, MutantIds $flaky): Checked
    {
        $survivors = Mutants::none();

        foreach ($mutants as $mutant) {
            $survives = $mutant->status() === MutantStatus::Survived && ! $flaky->has($mutant->id());
            $survivors = $survives ? $survivors->with($mutant) : $survivors;
        }

        $checker = $this->adapters->checker;

        return $checker instanceof NoAnalyser || $survivors->count() === 0
            ? new Checked($mutants, SurvivorChecks::none())
            : $this->warmedUp($checker, $mutants, $survivors);
    }

    /** The survivors checked, once the analyser has said who it is and run over the originals; or every one left. */
    private function warmedUp(StaticChecker $checker, Mutants $mutants, Mutants $survivors): Checked
    {
        $identity = $checker->identity($this->adapters->withheld);
        $warm = $identity instanceof AnalyserIdentity
            ? $checker->findings(Paths::none(), $this->adapters->withheld)
            : $identity;

        return match (true) {
            $identity instanceof CannotJudge => new Checked(
                $mutants,
                $this->leaving($survivors, Unchecked::Unidentified),
            ),
            $warm instanceof CannotJudge => new Checked($mutants, $this->leaving($survivors, Unchecked::NoWarmUp)),
            default => $this->each($checker, $identity, $warm, $mutants, $survivors),
        };
    }

    private function leaving(Mutants $survivors, Unchecked $why): SurvivorChecks
    {
        $checks = SurvivorChecks::none();

        foreach ($survivors as $survivor) {
            $checks = $checks->leaving(UncheckedSurvivor::of($why, $survivor->location()->file()));
        }

        return $checks;
    }

    /**
     * Each survivor checked in turn against its original's findings: the
     * warm-up's for a file as written, and for one printed as the runner
     * prints, the print's, found once for each file.
     */
    private function each(
        StaticChecker $checker,
        AnalyserIdentity $identity,
        Findings $warm,
        Mutants $mutants,
        Mutants $survivors,
    ): Checked {
        $history = AnalyserHistory::of($identity->analyser());
        $checks = SurvivorChecks::none();
        $baselines = [];
        $rejected = Mutants::none();

        foreach ($survivors as $survivor) {
            [$answer, $history, $baselines] = $this->one($checker, $identity, $warm, $survivor, $history, $baselines);
            $checks = $answer instanceof Unchecked
                ? $checks->leaving(UncheckedSurvivor::of($answer, $survivor->location()->file()))
                : $checks;
            $rejected = $answer instanceof Mutant ? $rejected->with($answer) : $rejected;
        }

        return new Checked($mutants->replacing($rejected), $checks->timing($history));
    }

    /**
     * One survivor checked, where the time left has room for it and its
     * runner gives it: killed, as it was, or why it is left.
     *
     * @param  array<string, Findings|Unchecked>                                        $baselines by file
     * @return array{Mutant|Findings|Unchecked, AnalyserHistory, array<string, Findings|Unchecked>}
     */
    private function one(
        StaticChecker $checker,
        AnalyserIdentity $identity,
        Findings $warm,
        Mutant $survivor,
        AnalyserHistory $history,
        array $baselines,
    ): array {
        $checkable = $this->fits($history) ? $this->adapters->runner->checkable($survivor) : Unchecked::OutOfTime;

        if (! $checkable instanceof Checkable) {
            return [$checkable instanceof Unchecked ? $checkable : Unchecked::NoMutant, $history, $baselines];
        }

        $file = $survivor->location()->file()->value();
        [$baseline, $history] = array_key_exists($file, $baselines)
            ? [$baselines[$file], $history]
            : $this->baseline($checker, $survivor, $checkable, $warm, $history);
        $baselines = array_replace($baselines, [$file => $baseline]);

        return $baseline instanceof Findings
            ? [...$this->answer($checker, $survivor, $checkable, $baseline, $history, $identity), $baselines]
            : [$baseline, $history, $baselines];
    }

    /** Whether the time left has room for one more check, as long as the checks so far took on average. */
    private function fits(AnalyserHistory $history): bool
    {
        $each = $history->time()->each();

        $each = $each instanceof Seconds ? $each : Seconds::of(0.0);

        return ! $this->deadline instanceof Deadline || $this->deadline->fitting(1, $each, $this->clock->now()) > 0;
    }

    /**
     * What a survivor's original analyses to: the warm-up's findings for a
     * file as written, and for one printed as its runner prints, the
     * print's, where they are the warm-up's; or why the survivor is left.
     *
     * @return array{Findings|Unchecked, AnalyserHistory}
     */
    private function baseline(
        StaticChecker $checker,
        Mutant $survivor,
        Checkable $checkable,
        Findings $warm,
        AnalyserHistory $history,
    ): array {
        $original = $checkable->original();

        if (! $original instanceof Contents) {
            return [$warm, $history];
        }

        $at = Workspace::checkedOriginal($survivor->id());
        [$print, $history] = $this->analysed($checker, $survivor->location()->file(), $at, $original, $history);

        return [$print instanceof Findings && ! $print->same($warm) ? Unchecked::PrintDiffers : $print, $history];
    }

    /**
     * A survivor, killed by static analysis where its check finds an error
     * its original does not have, and as it was where it finds none; or why
     * it is left.
     *
     * @return array{Mutant|Unchecked|Findings, AnalyserHistory}
     */
    private function answer(
        StaticChecker $checker,
        Mutant $survivor,
        Checkable $checkable,
        Findings $baseline,
        AnalyserHistory $history,
        AnalyserIdentity $identity,
    ): array {
        $at = Workspace::checkedMutant($survivor->id());
        $file = $survivor->location()->file();
        [$findings, $history] = $this->analysed($checker, $file, $at, $checkable->mutant(), $history);

        if (! $findings instanceof Findings) {
            return [$findings, $history];
        }

        foreach ($findings->newErrors($baseline) as $error) {
            return [$survivor->rejected(Rejection::by($identity->analyser(), $error)), $history];
        }

        return [$findings, $history];
    }

    /**
     * What the analyser finds in this text read in place of the file,
     * written for the check alone and removed after it, with the check's
     * time learned; or why it finds nothing.
     *
     * @return array{Findings|Unchecked, AnalyserHistory}
     */
    private function analysed(
        StaticChecker $checker,
        Path $file,
        Path $at,
        Contents $text,
        AnalyserHistory $history,
    ): array {
        if ($this->adapters->project->write($at, $text) instanceof CannotJudge) {
            return [Unchecked::Failed, $history];
        }

        $started = $this->clock->now();
        $found = $checker->check(MutantCheck::of($file, $at)->withholding($this->adapters->withheld));
        $history = $history->checked(Seconds::between($started, $this->clock->now()));
        $this->adapters->project->remove($at);

        return [match (true) {
            $found instanceof OutOfScope => Unchecked::OutOfScope,
            $found instanceof CannotJudge => Unchecked::Failed,
            default => $found,
        }, $history];
    }
}
