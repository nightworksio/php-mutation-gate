<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_keys;
use function array_values;
use function implode;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\MutantSites;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Group;

use function sprintf;

/**
 * The plan's one run that makes every mutant of each file its shards may
 * mutate, whole and side by side, and leaves the table of their change places
 * beside the plan for every shard (see ChangeTable, ADR-0004), with where
 * each mutant starts, which the plan prices a file it has not timed by. It
 * runs under the patch alone, whose plugin makes the table: an unpatched
 * Pest cuts no run into parts.
 *
 * @internal
 */
final readonly class ChangeTabling
{
    private const string LISTING = 'change-places.files';

    private const string LINE = "\n";

    private const string UNMADE = 'Pest left no table of the change places it was to make, and printed: %s';

    public function __construct(
        private Project $project,
        private Shell $shell,
        private Patching $patching,
        private Bridges $bridges,
    ) {
    }

    /**
     * Where each mutant of each of these files that is on disk starts, as the
     * table holds them; or why it was not made.
     */
    public function made(Paths $files, Mutators $mutators, Withheld $withheld): MutantSites|CannotJudge
    {
        $table = $this->project->fresh(ChangeTable::FILE);
        $onDisk = $this->onDisk($files);
        $canary = $this->patching->canary();

        if (! is_string($table) || $onDisk === [] || ! $canary instanceof Group || ! $this->patching->isOn()) {
            return $table instanceof CannotJudge ? $table : MutantSites::none();
        }

        $listing = $this->project->written(self::LISTING, implode(self::LINE, array_keys($onDisk)));

        if ($listing instanceof CannotJudge) {
            return $listing;
        }

        $drivers = CoverageDrivers::of($this->patching, recordsWithPcov: false, project: $this->project);
        $ran = $this->shell->run($drivers->mutating(
            Invocation::of($this->project)
                ->tabling($withheld, $canary, array_values($onDisk)[0], $mutators, $this->bridges)
                ->with([
                    GateVariable::Tabling->value => $listing,
                    GateVariable::Places->value => $table,
                    GateVariable::MakingSideBySide->value => '1',
                ]),
        ));
        $this->project->without($listing);

        return is_file($table)
            ? ChangeTable::in($table)->sitesOf($this->project, $files)
            : CannotJudge::because(sprintf(self::UNMADE, $ran->output()));
    }

    /**
     * Each of these files that is on disk, by where it is.
     *
     * @return array<string, Path>
     */
    private function onDisk(Paths $files): array
    {
        $onDisk = [];

        foreach ($files as $file) {
            $absolute = $this->project->absolute($file);
            $onDisk += is_file($absolute) ? [$absolute => $file] : [];
        }

        return $onDisk;
    }
}
