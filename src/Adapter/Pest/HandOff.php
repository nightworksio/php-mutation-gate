<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;
use function array_keys;

use Closure;

use function count;
use function file_get_contents;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\Verdicts;
use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\Analysis\NoPreCheck;
use NightWorksIO\MutationGate\Core\Analysis\PreCheckable;
use NightWorksIO\MutationGate\Core\Analysis\PreCheckables;
use NightWorksIO\MutationGate\Core\Analysis\PreChecker;
use NightWorksIO\MutationGate\Core\Analysis\Rejection;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\OwnTime;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\PrintsDeclarations;
use NightWorksIO\MutationGate\Core\Mutant\ProvesBeforeRun;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\ProcessWatch;
use NightWorksIO\MutationGate\Core\Subsumption\Subsumers;
use NightWorksIO\MutationGate\Core\Subsumption\Subsumption;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

use function sprintf;

/**
 * The hand-off of a patched Pest run's mutants to static analysis before
 * their tests (ADR-0020). While Pest runs, each look reads the
 * results file; once the plugin has written every mutant Pest planned, and
 * waits, the hand-off offers each covered mutant that is no twin to the
 * pre-checker, as Pest prints it, behind its covering tests' own time, and
 * writes the verdicts file the plugin waits for: the mutated copy of each
 * mutant rejected, whose run Pest then skips. Before that, where
 * `outbreak.so` loads, every planned mutant that is no twin is compiled
 * beside its original (ADR-0130): one proven equivalent is skipped the same
 * way and never offered to the pre-checker, and under
 * `mutants.duplicates: judge-once` one that compiles as a mutant made before
 * it at the same lines does is skipped, and judged as that one's twin
 * (ADR-0137). It writes the file however the checks end, so Pest never waits
 * on a check that failed. A mutant and its twins share their copy, and so
 * their rejection.
 *
 * @internal
 */
final class HandOff implements ProcessWatch
{
    /** @var array<string, Rejection> each rejection, by the mutated copy of the mutant it rejected */
    private array $rejected = [];

    /** @var array<string, Reason> each proof of equivalence, by the mutated copy of the mutant it proved */
    private array $equivalent = [];

    /** @var array<string, string> the copy of the mutant that judges each duplicate, by the duplicate's copy */
    private array $duplicates = [];

    private bool $handed = false;

    /** @param Closure(): (Covering|CannotJudge) $covering the run's covering tests, read once all are planned */
    public function __construct(
        private readonly Project $project,
        private readonly string $results,
        private readonly PreChecker $checker,
        private readonly ProcessCount $side,
        private readonly Closure $covering,
        private readonly Bridges $bridges,
        private readonly Subsumption|NotGiven $held = new NotGiven(),
        private readonly ProvesBeforeRun|NotGiven $equivalence = new NotGiven(),
        private readonly bool $duplicatesOnce = true,
    ) {
    }

    /**
     * Whether a run hands its mutants off: to a pre-checker, to be held back
     * by which subsumes which, or to be proven equivalent before they run.
     */
    public static function isWanted(
        PreChecker $checker,
        Subsumption|NotGiven $held,
        ProvesBeforeRun|NotGiven $equivalence = new NotGiven(),
    ): bool {
        return ! $checker instanceof NoPreCheck
            || $held instanceof Subsumption
            || $equivalence instanceof ProvesBeforeRun;
    }

    public function look(): void
    {
        if ($this->handed) {
            return;
        }

        $records = Records::in($this->results);

        if (! $records instanceof Records || ! $records->allMade()) {
            return;
        }

        $this->handed = true;
        $held = [];

        try {
            $this->proven($records);
            $held = $this->heldBack($records);
            $this->rejected = $this->checker instanceof NoPreCheck ? [] : $this->checked($records);
        } finally {
            Verdicts::write(
                Verdicts::beside($this->results),
                [...array_keys($this->equivalent), ...array_keys($this->duplicates), ...array_keys($this->rejected)],
                $held,
            );
        }
    }

    /** What rejected a planned mutant, or a twin, by its mutated copy; nothing where nothing did. */
    public function rejectionOf(PlannedMutant $mutant): Rejection|NotGiven
    {
        $copy = $mutant->mutated()->value();

        return array_key_exists($copy, $this->rejected) ? $this->rejected[$copy] : NotGiven::value();
    }

    /** What proved a planned mutant equivalent before it ran; nothing where nothing did. */
    public function equivalenceOf(PlannedMutant $mutant): Reason|NotGiven
    {
        $copy = $mutant->mutated()->value();

        return array_key_exists($copy, $this->equivalent) ? $this->equivalent[$copy] : NotGiven::value();
    }

    /**
     * A planned mutant as the run judges it: the twin of the mutant made
     * before it that compiles as it does, served as that one's copy, where it
     * is a duplicate; as it is otherwise.
     */
    public function judgedAs(PlannedMutant $mutant): PlannedMutant
    {
        $copy = $mutant->mutated()->value();

        return array_key_exists($copy, $this->duplicates)
            ? $mutant->asTwin()->servedAs(DiskPath::of($this->duplicates[$copy]))
            : $mutant;
    }

    public function progress(): string
    {
        return '';
    }

    /**
     * Each planned mutant that is no twin compiled beside its original, where
     * the run proves mutants before they run: those proven equivalent, and,
     * of the rest, under judge-once, those that compile as one made before
     * them at the same lines, each by its copy.
     */
    private function proven(Records $records): void
    {
        if (! $this->equivalence instanceof ProvesBeforeRun) {
            return;
        }

        $pairs = [];
        $prints = [];
        $sites = [];
        $copies = [];

        foreach ($records->planned() as $mutant) {
            $file = $mutant->file()->value();
            $prints[$file] ??= $this->printOfFile($file);
            $checkable = $this->checkable($prints[$file], $mutant);

            $original = $checkable instanceof Checkable ? $checkable->original() : NotGiven::value();

            if ($mutant->isTwin() || ! $checkable instanceof Checkable || ! $original instanceof Contents) {
                continue;
            }

            $copy = $mutant->mutated()->value();
            $pairs[] = [$copy, $original, $checkable->mutant()];
            $sites[$copy] = sprintf('%s:%d:%d', $file, $mutant->start()->number(), $mutant->end()->number());
            $copies[$copy] = $checkable->mutant();
        }

        $proofs = $this->equivalence->proven($pairs);
        $this->equivalent = $proofs->proofs;
        $this->duplicates = $this->duplicatesOnce
            ? $this->duplicatesAmong($this->equivalence, $sites, $proofs->programs, $copies)
            : [];
    }

    /**
     * Of these mutants, by copy, each not proven equivalent whose program is
     * that of one made before it at the same lines: the same op arrays, and
     * the same declarations, which op arrays do not hold; and the copy of
     * that one.
     *
     * @param  array<string, string>   $sites    each mutant's file and lines, by its copy, in the order Pest made them
     * @param  array<string, string>   $programs each mutant's digest of its op arrays, by its copy
     * @param  array<string, Contents> $copies   each mutant's copy as Pest serves it, by its copy
     * @return array<string, string>
     */
    private function duplicatesAmong(PrintsDeclarations $printer, array $sites, array $programs, array $copies): array
    {
        $firsts = [];
        $duplicates = [];

        foreach ($sites as $copy => $site) {
            if (array_key_exists($copy, $this->equivalent) || ! array_key_exists($copy, $programs)) {
                continue;
            }

            $program = sprintf('%s %s', $site, $programs[$copy]);
            $same = $this->declaringAlike($printer, $copies[$copy], $firsts[$program] ?? [], $copies);

            if ($same === '') {
                $firsts[$program][] = $copy;
            } else {
                $duplicates[$copy] = $same;
            }
        }

        return $duplicates;
    }

    /**
     * The first of these mutants, by copy, that declares what this copy
     * declares; none where none does.
     *
     * @param list<string>            $candidates
     * @param array<string, Contents> $copies
     */
    private function declaringAlike(
        PrintsDeclarations $printer,
        Contents $copy,
        array $candidates,
        array $copies,
    ): string {
        $declared = $candidates === [] ? NotGiven::value() : $printer->declarations($copy);

        foreach ($candidates as $candidate) {
            if (is_string($declared) && $declared === $printer->declarations($copies[$candidate])) {
                return $candidate;
            }
        }

        return '';
    }

    /** A file as Pest prints it, by its path, or why it cannot be printed. */
    private function printOfFile(string $file): Contents|CannotJudge
    {
        $relative = $this->project->relative($file);
        $written = file_get_contents($file);

        return is_string($written) ? Printed::of(Contents::of($written), $relative) : CannotJudge::because($file);
    }

    /**
     * Each covered planned mutant offered to the pre-checker, and what it
     * rejected, by mutated copy.
     *
     * @return array<string, Rejection>
     */
    private function checked(Records $records): array
    {
        $covering = ($this->covering)();

        if ($covering instanceof CannotJudge) {
            return [];
        }

        $planned = $records->planned();
        $ids = Identities::of(Root::of($this->project->root()), $planned);
        $map = $covering->map($this->project);
        $prints = [];
        $offered = [];
        $copies = [];

        foreach ($planned as $at => $mutant) {
            $tests = $covering->testsCovering($mutant->file(), $mutant->start(), $mutant->end());
            $gate = $this->unrun($ids[$at], $mutant);
            $file = $gate->location()->file()->value();
            $prints[$file] ??= $this->printOf($gate);
            $checkable = $this->checkable($prints[$file], $mutant);

            $decided = array_key_exists($mutant->mutated()->value(), $this->equivalent)
                || array_key_exists($mutant->mutated()->value(), $this->duplicates);

            if ($mutant->isTwin() || $decided || count($tests) === 0 || ! $checkable instanceof Checkable) {
                continue;
            }

            $offered[] = PreCheckable::of($gate, $checkable, $this->seconds(OwnTime::of($map, $tests)));
            $copies[$gate->id()->value()] = $mutant->mutated()->value();
        }

        $rejections = $this->checker->rejected(PreCheckables::of(...$offered), $this->side);
        $rejected = [];

        foreach ($offered as $offer) {
            $rejection = $rejections->of($offer->mutant()->id());
            $rejected = $rejection instanceof Rejection
                ? [...$rejected, $copies[$offer->mutant()->id()->value()] => $rejection]
                : $rejected;
        }

        return $rejected;
    }

    /**
     * The mutated copy of each planned mutant that is no twin and that
     * another subsumes, by what the run holds subsumed mutants back by; none
     * where it holds none back.
     *
     * @return list<string>
     */
    private function heldBack(Records $records): array
    {
        if (! $this->held instanceof Subsumption) {
            return [];
        }

        $planned = $records->planned();
        $ids = Identities::of(Root::of($this->project->root()), $planned);
        $mutants = [];
        $copies = [];

        foreach ($planned as $at => $mutant) {
            $gate = $this->unrun($ids[$at], $mutant);
            $mutants = $mutant->isTwin() ? $mutants : [...$mutants, $gate];
            $copies[$gate->id()->key()] = $mutant->mutated()->value();
        }

        $subsumers = Subsumers::among($this->held, ...$mutants);
        $held = [];

        foreach ($mutants as $mutant) {
            $held = $subsumers->holds($mutant->id()) ? [...$held, $copies[$mutant->id()->key()]] : $held;
        }

        return $held;
    }

    /** A planned mutant as the gate names it, before its run: unjudged, unmeasured. */
    private function unrun(MutantId $id, PlannedMutant $planned): Mutant
    {
        return Mutant::of(
            $id,
            $planned->id(),
            Location::of($this->project->relative($planned->file()->value()), $planned->start(), $planned->end()),
            $planned->mutation($this->bridges),
            MutantStatus::Unjudged,
            Unmeasured::duration(),
        );
    }

    /** A mutant's file as Pest prints it, or why it cannot be printed. */
    private function printOf(Mutant $mutant): Contents|CannotJudge
    {
        $file = $mutant->location()->file();
        $written = file_get_contents($this->project->absolute($file));

        return is_string($written) ? Printed::of(Contents::of($written), $file) : CannotJudge::because($file->value());
    }

    /** A mutant as the analyser reads it: the mutated copy Pest serves, judged against the print of its file. */
    private function checkable(Contents|CannotJudge $print, PlannedMutant $mutant): Checkable|CannotJudge
    {
        if ($print instanceof CannotJudge) {
            return $print;
        }

        $copy = file_get_contents($mutant->mutated()->value());

        return is_string($copy)
            ? Checkable::printed($print, Contents::of($copy))
            : CannotJudge::because($mutant->mutated()->value());
    }

    private function seconds(Seconds|Unmeasured $took): Seconds
    {
        return $took instanceof Seconds ? $took : Seconds::of(0.0);
    }
}
