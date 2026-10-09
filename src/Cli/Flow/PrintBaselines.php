<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_combine;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_values;
use function count;

use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\Analysis\MutantCheck;
use NightWorksIO\MutationGate\Core\Analysis\MutantChecks;
use NightWorksIO\MutationGate\Core\Analysis\OutOfScope;
use NightWorksIO\MutationGate\Core\Analysis\PreCheckable;
use NightWorksIO\MutationGate\Core\Analysis\Unchecked;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * What each mutant's original analyses to, for a check before the tests
 * (ADR-0020, decision 9): the warm-up's findings for a file as written, and
 * for one its runner prints, the print's, checked once for each file, side by
 * side, where they are the warm-up's. A file whose print analyses otherwise
 * leaves its mutants to their tests.
 */
final readonly class PrintBaselines
{
    /** @param array<string, Findings|Unchecked|CannotJudge> $printed what each file's print analyses to, by file */
    public function __construct(
        private Adapters $adapters,
        private WarmedUp $warm,
        private Seconds $perCheck,
        private array $printed = [],
    ) {
    }

    /**
     * These baselines, with the print of each file these mutants are printed
     * from analysed once.
     *
     * @param list<PreCheckable> $mutants
     */
    public function of(array $mutants, ProcessCount $side): self
    {
        $files = [];

        foreach ($mutants as $mutant) {
            $original = $mutant->checkable()->original();
            $file = $mutant->mutant()->location()->file();

            if (! $original instanceof Contents || array_key_exists($file->value(), $files)) {
                continue;
            }

            $at = Workspace::checkedOriginal($mutant->mutant()->id());
            $written = $this->adapters->project->write($at, $original);
            $files[$file->value()] = $written instanceof CannotJudge ? $written : MutantCheck::of($file, $at)
                ->within($this->perCheck)
                ->withholding($this->adapters->withheld);
        }

        return new self($this->adapters, $this->warm, $this->perCheck, $this->analysed($files, $side));
    }

    /** What a mutant's original analyses to; or why its mutants are left to their tests. */
    public function baseline(PreCheckable $mutant): Findings|Unchecked|CannotJudge
    {
        $file = $mutant->mutant()->location()->file()->value();

        return match (true) {
            ! $mutant->checkable()->original() instanceof Contents => $this->warm->findings,
            array_key_exists($file, $this->printed) => $this->printed[$file],
            default => Unchecked::PrintDiffers,
        };
    }

    /**
     * The files a mutant can break, read against its original as its runner
     * gives it: the print, or the file as it is written.
     */
    public static function dependents(Adapters $adapters, WarmedUp $warm, PreCheckable $mutant): Paths
    {
        $original = $mutant->checkable()->original();
        $file = $mutant->mutant()->location()->file();
        $text = $original instanceof Contents ? $original : $adapters->project->read($file);

        return $text instanceof Contents
            ? $warm->dependents->of($file, $text, $mutant->checkable()->mutant())
            : Paths::none();
    }

    /**
     * Each print checked side by side, standing where it analyses as the
     * warm-up found the files, removed after.
     *
     * @param  array<string, MutantCheck|CannotJudge>          $files by file
     * @return array<string, Findings|Unchecked|CannotJudge>
     */
    private function analysed(array $files, ProcessCount $side): array
    {
        $checks = array_filter($files, static fn(object $check): bool => $check instanceof MutantCheck);
        $unwritten = array_filter($files, static fn(object $check): bool => $check instanceof CannotJudge);
        $answers = [...$this->warm->checker->checks(MutantChecks::of(...array_values($checks)), $side)];
        $answered = count($answers) === count($checks) ? array_combine(array_keys($checks), $answers) : [];

        foreach ($checks as $check) {
            $this->adapters->project->remove($check->mutant());
        }

        return [
            ...$this->printed,
            ...$unwritten,
            ...array_map($this->standing(...), $answered),
        ];
    }

    /** A print's findings where they are the warm-up's; or why its file's mutants are left to their tests. */
    private function standing(Findings|OutOfScope|CannotJudge $answer): Findings|Unchecked|CannotJudge
    {
        return match (true) {
            $answer instanceof Findings && ! $answer->same($this->warm->findings) => Unchecked::PrintDiffers,
            $answer instanceof OutOfScope => Unchecked::OutOfScope,
            default => $answer,
        };
    }
}
