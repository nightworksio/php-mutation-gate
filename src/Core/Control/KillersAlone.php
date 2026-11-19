<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Control;

use function array_key_exists;
use function count;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Mutant\Evidences;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\RunSelection;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/**
 * The controls of kills run by the tests that killed each alone, as a runner
 * that replays each kill's order itself, or `flaky.killControls: alone`,
 * has them (see KillControls): each kill's own, by its killers or as its run
 * selected them where that run left its filter out, and its file's, every
 * killer of the file's kills at once.
 *
 * @internal
 */
final readonly class KillersAlone
{
    /**
     * @param array<string, Control> $files each kill's file's control, by the mutant's gate id
     * @param array<string, Control> $kills each kill's own control, by the mutant's gate id
     */
    private function __construct(private array $files, private array $kills)
    {
    }

    /**
     * The controls of each of these kills, run by the tests that killed it,
     * alone. Under `pest.longFilter: until-killer`, a kill whose run left its
     * filter out and whose runner replays its order up to its last killer
     * (ADR-0138) is controlled by its killers too: that replay already ran
     * every test the run executed before them.
     */
    public static function of(
        Mutants $mutants,
        Evidences $evidence,
        CoverageMap $map,
        RunSelection $run,
        LimitBounds $bounds,
        bool $replayed,
    ): self {
        $kills = [];

        foreach ($mutants as $mutant) {
            if ($mutant->status() === MutantStatus::Killed && count($mutant->killers()) > 0) {
                $found = $replayed ? Evidences::none() : $evidence;
                $kills[$mutant->id()->key()] = self::ownOf($mutant, $found, $map, $run, $bounds);
            }
        }

        return new self(self::forFiles($kills, $map, $run, $bounds), $kills);
    }

    /**
     * Each kill's file's control, by the mutant's gate id.
     *
     * @return array<string, Control>
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * Each kill's own control, by the mutant's gate id.
     *
     * @return array<string, Control>
     */
    public function kills(): array
    {
        return $this->kills;
    }

    /** A kill's own control: its killers, or as its run selected them where that run left its filter out. */
    private static function ownOf(
        Mutant $mutant,
        Evidences $evidence,
        CoverageMap $map,
        RunSelection $run,
        LimitBounds $bounds,
    ): Control {
        $killers = $mutant->killers();
        $unfiltered = $evidence->of($mutant->id())->unfiltered();
        $tests = $unfiltered instanceof NotGiven ? $killers : $run->of($killers, $map, $unfiltered);

        return Control::standard($mutant->location()->file(), $tests, $map, $bounds);
    }

    /**
     * What vouches for each kill at once: every killer of its file's kills
     * chosen by their tests alone; every test file the file's other kills'
     * runs loaded where they left their filter out, selected as those runs
     * selected them; and a kill selected otherwise by itself.
     *
     * @param  array<string, Control> $kills by the mutant's gate id
     * @return array<string, Control>
     */
    private static function forFiles(array $kills, CoverageMap $map, RunSelection $run, LimitBounds $bounds): array
    {
        $killers = self::killersByBatch($kills);
        $loaded = self::loadedByFile($kills);
        $files = [];

        foreach ($kills as $key => $own) {
            $file = $own->file();
            $files[$key] = match (true) {
                $own->selected() instanceof NotGiven
                    => Control::standard($file, $killers[self::batchOf($own)], $map, $bounds),
                $own->selected() instanceof Paths => Control::standard(
                    $file,
                    $run->of($killers[self::batchOf($own)], $map, $loaded[$file->value()]),
                    $map,
                    $bounds,
                ),
                default => $own,
            };
        }

        return $files;
    }

    /**
     * Every test of each batch's kills: a file's kills chosen by their tests'
     * names, and apart from them its kills whose runs left their filter out.
     *
     * @param  array<string, Control> $kills by the mutant's gate id
     * @return array<string, TestIds> by the batch
     */
    private static function killersByBatch(array $kills): array
    {
        $killers = [];

        foreach ($kills as $own) {
            $batch = self::batchOf($own);
            $killers[$batch] = (array_key_exists($batch, $killers) ? $killers[$batch] : TestIds::none())
                ->and($own->tests());
        }

        return $killers;
    }

    /**
     * Every test file the runs of each file's kills loaded where they left their filter out.
     *
     * @param  array<string, Control> $kills by the mutant's gate id
     * @return array<string, Paths> by the file's path
     */
    private static function loadedByFile(array $kills): array
    {
        $loaded = [];

        foreach ($kills as $own) {
            $file = $own->file()->value();
            $known = array_key_exists($file, $loaded) ? $loaded[$file] : Paths::none();
            $selected = $own->selected();
            $loaded[$file] = $selected instanceof Paths ? $known->and($selected) : $known;
        }

        return $loaded;
    }

    /**
     * The batch a kill's control is first run in: its file's kills chosen by
     * their tests' names, or its file's kills whose runs left their filter
     * out; a kill selected otherwise is a batch of its own.
     */
    private static function batchOf(Control $own): string
    {
        $selected = $own->selected();

        return $selected instanceof NotGiven || $selected instanceof Paths
            ? JsonText::compact([$own->file()->value(), $selected instanceof Paths])
            : $own->key();
    }
}
