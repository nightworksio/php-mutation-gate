<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report\Page;

use function array_key_exists;
use function count;

use NightWorksIO\MutationGate\Core\Report\JsonReport;
use NightWorksIO\MutationGate\Core\Report\MutantJson;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\Scoring;

use function usort;

/**
 * The mutants of a JSON report as the HTML report shows them: what each came
 * to, the survivors, those on changed lines first, and the files, those with
 * the most survivors first. A file of a unit this run did not judge, whose
 * result a proof or an earlier run carried, holds its counts alone.
 *
 * @phpstan-import-type Report from JsonReport
 * @phpstan-import-type MutantEntry from MutantJson
 *
 * @phpstan-type FileEntry array{
 *     path: string,
 *     carried: bool,
 *     survivors: int,
 *     counts: array<string, int>,
 *     mutants: list<MutantEntry>,
 * }
 */
final readonly class Mutants
{
    /**
     * @param list<MutantEntry>    $mutants
     * @param array<string, bool>  $carried whether each unit's result was carried, by its path
     */
    private function __construct(private array $mutants, private Uncovered $uncovered, private array $carried)
    {
    }

    /** @param Report $report */
    public static function of(array $report): self
    {
        $carried = [];

        foreach ($report['trees'] as $tree) {
            foreach ($tree['units'] as $unit) {
                $carried[$unit['path']] = $unit['origin'] !== Origin::Run->value;
            }
        }

        return new self($report['mutants'], Uncovered::from($report['uncovered']), $carried);
    }

    /** @param MutantEntry $mutant */
    public static function outcomeOf(array $mutant): Outcome
    {
        return Outcome::of(MutantJudgement::from($mutant['judgement']));
    }

    public function count(): int
    {
        return count($this->mutants);
    }

    /** How many mutants are on a line the change touched. */
    public function onChangedLines(): int
    {
        $changed = 0;

        foreach ($this->mutants as $mutant) {
            $changed += $mutant['changedLine'] ? 1 : 0;
        }

        return $changed;
    }

    /**
     * The mutants the score counts as not killed, those on changed lines
     * first, then by file and line.
     *
     * @return list<MutantEntry>
     */
    public function survivors(): array
    {
        $survivors = [];

        foreach ($this->mutants as $mutant) {
            if ($this->survives($mutant)) {
                $survivors[] = $mutant;
            }
        }

        usort($survivors, static fn(array $one, array $other): int => self::placed($one) <=> self::placed($other));

        return $survivors;
    }

    /**
     * Each file, those with the most survivors first, then by path; each
     * file's mutants those that need a test first, then by line.
     *
     * @return list<FileEntry>
     */
    public function files(): array
    {
        $files = [];

        foreach ($this->mutants as $mutant) {
            $path = $mutant['file'];
            $file = array_key_exists($path, $files) ? $files[$path] : $this->file($path);
            $file['mutants'][] = $mutant;
            ++$file['counts'][self::outcomeOf($mutant)->value];
            $file['survivors'] += $this->survives($mutant) ? 1 : 0;
            $files[$path] = $file;
        }

        $listed = [];

        foreach ($files as $file) {
            usort($file['mutants'], $this->byNeed(...));
            $listed[] = [...$file, 'mutants' => $file['carried'] ? [] : $file['mutants']];
        }

        usort($listed, static fn(array $one, array $other): int => self::listed($one) <=> self::listed($other));

        return $listed;
    }

    /** @param MutantEntry $mutant */
    private function survives(array $mutant): bool
    {
        return MutantJudgement::from($mutant['judgement'])->scoring($this->uncovered) === Scoring::NotKilled;
    }

    /** @return FileEntry */
    private function file(string $path): array
    {
        $counts = [];

        foreach (Outcome::cases() as $outcome) {
            $counts[$outcome->value] = 0;
        }

        return [
            'path' => $path,
            'carried' => array_key_exists($path, $this->carried) && $this->carried[$path],
            'survivors' => 0,
            'counts' => $counts,
            'mutants' => [],
        ];
    }

    /**
     * Where a file goes among them: the most survivors first, then by path.
     *
     * @param  FileEntry         $file
     * @return array{int, string}
     */
    private static function listed(array $file): array
    {
        return [-$file['survivors'], $file['path']];
    }

    /**
     * Where a survivor goes among them: on a changed line first, then by file and line.
     *
     * @param  MutantEntry          $mutant
     * @return array{int, string, int}
     */
    private static function placed(array $mutant): array
    {
        return [$mutant['changedLine'] ? 0 : 1, $mutant['file'], $mutant['line']];
    }

    /**
     * @param MutantEntry $one
     * @param MutantEntry $other
     */
    private function byNeed(array $one, array $other): int
    {
        return [self::outcomeOf($one)->rank(), $one['line'], $one['id']]
            <=> [self::outcomeOf($other)->rank(), $other['line'], $other['id']];
    }
}
