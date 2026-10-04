<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_key_exists;
use function array_replace;

use NightWorksIO\MutationGate\Core\Analysis\AnalyserHistory;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\Analysis\DependentCap;
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
 * place of its file, as its runner gives it, with the files it can break
 * where it changes what its file declares and the analyser reads them. A
 * survivor with an error its original does not have is killed by static
 * analysis. One the analyser cannot check, or the time budget has no room
 * for, stays a survivor, and the checks say why. A survivor printed as its
 * runner prints its mutants is judged against its original printed the same
 * way, which must first analyse as the file itself does. The checks record
 * their time alone (ADR-0020, decision 11).
 */
final readonly class SurvivorChecking
{
    /** The checks a survivor needs on its own. */
    private const int ALONE = 1;

    /** The checks the first survivor of a printed file needs: the print, then the mutant. */
    private const int WITH_ITS_PRINT = self::ALONE + 1;

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

    /**
     * The survivors checked, where the analyser said who it is and the time
     * left has room for its run over the originals; or every one left.
     */
    private function warmedUp(StaticChecker $checker, Mutants $mutants, Mutants $survivors): Checked
    {
        $identity = $this->adapters->analyser;

        return match (true) {
            ! $identity instanceof AnalyserIdentity => new Checked(
                $mutants,
                $this->leaving($survivors, Unchecked::Unidentified),
            ),
            $this->deadline instanceof Deadline && $this->deadline->hasPassed($this->clock->now()) => new Checked(
                $mutants,
                $this->leaving($survivors, Unchecked::OutOfTime),
            ),
            default => $this->warmed($checker, $identity, $mutants, $survivors),
        };
    }

    /** The analyser's one run over the originals, timed, then each survivor checked; or every one left. */
    private function warmed(
        StaticChecker $checker,
        AnalyserIdentity $identity,
        Mutants $mutants,
        Mutants $survivors,
    ): Checked {
        $started = $this->clock->now();
        $found = $checker->findings(Paths::none(), $this->adapters->withheld);
        $took = Seconds::between($started, $this->clock->now());

        if ($found instanceof CannotJudge) {
            return new Checked($mutants, $this->leaving($survivors, Unchecked::NoWarmUp));
        }

        $dependents = new Dependents(new NameGraph($this->adapters), DependentCap::standard());

        return $this->each(new WarmedUp($checker, $identity, $found, $took, $dependents), $mutants, $survivors);
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
    private function each(WarmedUp $warm, Mutants $mutants, Mutants $survivors): Checked
    {
        $history = AnalyserHistory::of($warm->identity->analyser());
        $checks = SurvivorChecks::none();
        $baselines = [];
        $rejected = Mutants::none();

        foreach ($survivors as $survivor) {
            [$answer, $history, $baselines] = $this->one($warm, $survivor, $history, $baselines);
            $file = $survivor->location()->file();
            $checks = match (true) {
                $answer instanceof Unchecked => $checks->leaving(UncheckedSurvivor::of($answer, $file)),
                $answer instanceof CannotJudge => $checks->leaving(UncheckedSurvivor::failed($answer->why(), $file)),
                default => $checks,
            };
            $rejected = $answer instanceof Mutant ? $rejected->with($answer) : $rejected;
        }

        return new Checked($mutants->replacing($rejected), $checks->timing($history));
    }

    /**
     * One survivor checked, where its runner gives it and the time left has
     * room for every check it needs: the print of its file too, where that
     * is not yet analysed. It is killed, as it was, or left with why.
     *
     * @param  array<string, Findings|Unchecked|CannotJudge> $baselines by file
     * @return array{
     *     Mutant|Findings|Unchecked|CannotJudge,
     *     AnalyserHistory,
     *     array<string, Findings|Unchecked|CannotJudge>,
     * }
     */
    private function one(WarmedUp $warm, Mutant $survivor, AnalyserHistory $history, array $baselines): array
    {
        $checkable = $this->adapters->runner->checkable($survivor);
        $file = $survivor->location()->file()->value();
        $cached = array_key_exists($file, $baselines);
        $printed = $checkable instanceof Checkable && $checkable->original() instanceof Contents && ! $cached;
        $needed = $printed ? self::WITH_ITS_PRINT : self::ALONE;

        if (! $checkable instanceof Checkable || ! $this->fits($history, $needed, $warm->took)) {
            return [$checkable instanceof Checkable ? Unchecked::OutOfTime : Unchecked::NoMutant, $history, $baselines];
        }

        [$baseline, $history] = $cached
            ? [$baselines[$file], $history]
            : $this->baseline($warm, $survivor, $checkable, $history);
        $baselines = array_replace($baselines, [$file => $baseline]);

        return $baseline instanceof Findings
            ? [...$this->answer($warm, $survivor, $checkable, $baseline, $history), $baselines]
            : [$baseline, $history, $baselines];
    }

    /**
     * Whether the time left has room for this many checks, each as long as
     * the checks so far took on average, or, before the first, as long as
     * the run over the originals took.
     */
    private function fits(AnalyserHistory $history, int $checks, Seconds $warmUp): bool
    {
        $measured = $history->time()->each();
        $each = $measured instanceof Seconds ? $measured : $warmUp;

        return ! $this->deadline instanceof Deadline
            || $this->deadline->fitting($checks, $each, $this->clock->now()) === $checks;
    }

    /**
     * What a survivor's original analyses to: the warm-up's findings for a
     * file as written, and for one printed as its runner prints, the
     * print's, where they are the warm-up's; or why the survivor is left.
     *
     * @return array{Findings|Unchecked|CannotJudge, AnalyserHistory}
     */
    private function baseline(WarmedUp $warm, Mutant $survivor, Checkable $checkable, AnalyserHistory $history): array
    {
        $original = $checkable->original();

        if (! $original instanceof Contents) {
            return [$warm->findings, $history];
        }

        $at = Workspace::checkedOriginal($survivor->id());
        [$print, $history] = $this->analysed(
            $warm->checker,
            MutantCheck::of($survivor->location()->file(), $at),
            $original,
            $history,
        );

        $differs = $print instanceof Findings && ! $print->same($warm->findings);

        return [$differs ? Unchecked::PrintDiffers : $print, $history];
    }

    /**
     * A survivor, killed by static analysis where its check finds an error
     * its original does not have, and as it was where it finds none; or why
     * it is left.
     *
     * @return array{Mutant|Unchecked|Findings|CannotJudge, AnalyserHistory}
     */
    private function answer(
        WarmedUp $warm,
        Mutant $survivor,
        Checkable $checkable,
        Findings $baseline,
        AnalyserHistory $history,
    ): array {
        $file = $survivor->location()->file();
        $check = MutantCheck::of($file, Workspace::checkedMutant($survivor->id()));
        $listed = $warm->checker->readsDependents()
            ? $check->withDependents($this->dependents($warm, $file, $checkable))
            : $check;
        [$findings, $history] = $this->analysed($warm->checker, $listed, $checkable->mutant(), $history);

        if (! $findings instanceof Findings) {
            return [$findings, $history];
        }

        foreach ($findings->newErrors($baseline) as $error) {
            return [$survivor->rejected(Rejection::by($warm->identity->analyser(), $error)), $history];
        }

        return [$findings, $history];
    }

    /**
     * The files a survivor can break, read against its original as its
     * runner gives it: the print, or the file as it is written.
     */
    private function dependents(WarmedUp $warm, Path $file, Checkable $checkable): Paths
    {
        $original = $checkable->original();
        $text = $original instanceof Contents ? $original : $this->adapters->project->read($file);

        return $text instanceof Contents ? $warm->dependents->of($file, $text, $checkable->mutant()) : Paths::none();
    }

    /**
     * What the analyser finds in this text read in place of the check's
     * file, written where the check names the mutant for it alone and
     * removed after it, with the check's time learned; or why it finds
     * nothing: out of its scope, or the reason the check could not run.
     *
     * @return array{Findings|Unchecked|CannotJudge, AnalyserHistory}
     */
    private function analysed(
        StaticChecker $checker,
        MutantCheck $check,
        Contents $text,
        AnalyserHistory $history,
    ): array {
        $written = $this->adapters->project->write($check->mutant(), $text);

        if ($written instanceof CannotJudge) {
            return [$written, $history];
        }

        $started = $this->clock->now();
        $found = $checker->check($check->withholding($this->adapters->withheld));
        $history = $history->checked(Seconds::between($started, $this->clock->now()));
        $this->adapters->project->remove($check->mutant());

        return [$found instanceof OutOfScope ? Unchecked::OutOfScope : $found, $history];
    }
}
