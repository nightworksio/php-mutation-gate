<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_combine;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function count;

use NightWorksIO\MutationGate\Core\Analysis\CheckableMutant;
use NightWorksIO\MutationGate\Core\Analysis\DifferingPrint;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\Analysis\MutantCheck;
use NightWorksIO\MutationGate\Core\Analysis\MutantChecks;
use NightWorksIO\MutationGate\Core\Analysis\OutOfScope;
use NightWorksIO\MutationGate\Core\Analysis\Unchecked;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * What each mutant's original analyses to, for a check before or after its
 * tests (ADR-0020): the warm-up's findings for a file as written, and
 * for one its runner prints, the print's, checked once for each file, side by
 * side. A print stands where it analyses as the warm-up found the files; or
 * as the file as written does when read from where the print is, since a
 * rule that reads the path of the file it analyses, such as one allowed in
 * some directories alone, reads that path, and each mutant's check is read
 * from there too. A file whose print analyses otherwise leaves its mutants to
 * their tests, saying which findings differ.
 */
final readonly class PrintBaselines
{
    /** How a differing print names the warm-up's findings it is weighed against. */
    private const string WARM_UP = 'the warm-up';

    /** How a differing print names the file as written, read where the print is. */
    private const string AS_WRITTEN = 'the file as written there';

    private const string UNREAD = 'The gate cannot read %s as written.';

    /**
     * @param array<string, Findings|DifferingPrint|Unchecked|CannotJudge> $printed what each file's print analyses
     *                                                                         to, by file
     */
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
     * @param list<CheckableMutant> $mutants
     */
    public function of(array $mutants, ProcessCount $side): self
    {
        $prints = [];
        $written = [];

        foreach ($mutants as $mutant) {
            $original = $mutant->checkable()->original();
            $file = $mutant->mutant()->location()->file();

            if (! $original instanceof Contents || array_key_exists($file->value(), $prints)) {
                continue;
            }

            $id = $mutant->mutant()->id();
            $prints[$file->value()] = $this->check($file, Workspace::checkedOriginal($id), $original);
            $written[$file->value()] = Workspace::checkedAsWritten($id);
        }

        $answers = $this->analysed($prints, $side);

        return new self($this->adapters, $this->warm, $this->perCheck, [
            ...$this->printed,
            ...$this->standing($answers, $written, $side),
        ]);
    }

    /** What a mutant's original analyses to; or why its mutants are left to their tests. */
    public function baseline(CheckableMutant $mutant): Findings|DifferingPrint|Unchecked|CannotJudge
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
    public static function dependents(Adapters $adapters, WarmedUp $warm, CheckableMutant $mutant): Paths
    {
        $original = $mutant->checkable()->original();
        $file = $mutant->mutant()->location()->file();
        $text = $original instanceof Contents ? $original : $adapters->project->read($file);

        return $text instanceof Contents
            ? $warm->dependents->of($file, $text, $mutant->checkable()->mutant())
            : Paths::none();
    }

    /**
     * Each file's print standing where it analyses as the warm-up found the
     * files, and the rest weighed against the file as written, read from
     * where its print is.
     *
     * @param  array<string, Findings|OutOfScope|CannotJudge>                $answers each print's analysis, by file
     * @param  array<string, Path>                                           $written where each file is read as
     *                                                                                written, by file
     * @return array<string, Findings|DifferingPrint|Unchecked|CannotJudge>
     */
    private function standing(array $answers, array $written, ProcessCount $side): array
    {
        $differing = array_filter(
            $answers,
            fn(Findings|OutOfScope|CannotJudge $answer): bool => $answer instanceof Findings
                && ! $answer->same($this->warm->findings),
        );
        $asWritten = $this->asWritten(array_keys($differing), $written, $side);
        $standing = [];

        foreach ($answers as $file => $answer) {
            $standing[$file] = match (true) {
                $answer instanceof OutOfScope => Unchecked::OutOfScope,
                $answer instanceof Findings && array_key_exists($file, $differing) => $this->against(
                    $answer,
                    array_key_exists($file, $asWritten) ? $asWritten[$file] : NotGiven::value(),
                ),
                default => $answer,
            };
        }

        return $standing;
    }

    /**
     * Each of these files as written, analysed from where its print is read.
     *
     * @param  list<string>                                    $files
     * @param  array<string, Path>                             $written where each file is read as written, by file
     * @return array<string, Findings|OutOfScope|CannotJudge>
     */
    private function asWritten(array $files, array $written, ProcessCount $side): array
    {
        $checks = [];

        foreach ($files as $file) {
            $text = $this->adapters->project->read(Path::of($file));
            $checks[$file] = $text instanceof Contents
                ? $this->check(Path::of($file), $written[$file], $this->uncached($text))
                : CannotJudge::because(sprintf(self::UNREAD, $file));
        }

        return $this->analysed($checks, $side);
    }

    /** A print unlike the warm-up, standing where the file as written, read from its place, analyses alike. */
    private function against(
        Findings $print,
        Findings|OutOfScope|CannotJudge|NotGiven $written,
    ): Findings|DifferingPrint {
        return match (true) {
            $written instanceof Findings && $print->same($written) => $print,
            $written instanceof Findings => DifferingPrint::between($print, $written, self::AS_WRITTEN),
            default => DifferingPrint::between($print, $this->warm->findings, self::WARM_UP),
        };
    }

    /**
     * The file as written, with a line added at its end, so an analyser that
     * keeps what it found of each file by the file's text analyses it again
     * rather than answer what it found of the file in its own place.
     */
    private function uncached(Contents $text): Contents
    {
        return Contents::of(sprintf('%s%s', $text->text(), PHP_EOL));
    }

    /** A file's text written where a check reads it in the file's place, or why it cannot be. */
    private function check(Path $file, Path $at, Contents $text): MutantCheck|CannotJudge
    {
        $written = $this->adapters->project->write($at, $text);

        return $written instanceof CannotJudge ? $written : MutantCheck::of($file, $at)
            ->within($this->perCheck)
            ->withholding($this->adapters->withheld);
    }

    /**
     * Each check run side by side, removed after; one that could not be
     * written answered by why.
     *
     * @param  array<string, MutantCheck|CannotJudge>          $files by file
     * @return array<string, Findings|OutOfScope|CannotJudge>
     */
    private function analysed(array $files, ProcessCount $side): array
    {
        $checks = array_filter($files, static fn(object $check): bool => $check instanceof MutantCheck);
        $unwritten = array_filter($files, static fn(object $check): bool => $check instanceof CannotJudge);
        $answers = $checks === [] ? [] : [...$this->warm->checker->checks(MutantChecks::of(...$checks), $side)];
        $answered = count($answers) === count($checks) ? array_combine(array_keys($checks), $answers) : [];

        foreach ($checks as $check) {
            $this->adapters->project->remove($check->mutant());
        }

        return [...$unwritten, ...$answered];
    }
}
