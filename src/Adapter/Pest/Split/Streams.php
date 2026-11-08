<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_unique;
use function array_values;

use FilesystemIterator;

use function getcwd;
use function getenv;
use function mkdir;

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Schemata;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Forking;
use Pest\Mutate\Event\Facade;
use Pest\Mutate\MutationSuite;
use Pest\Mutate\MutationTest;
use Pest\Mutate\Plugins\Mutate;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function rmdir;

use SplFileInfo;

use function sprintf;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * The split streams of a patched Pest mutation run (ADR-0117): in Pest's
 * own process, once the mutants are made, every mutant its file's schema
 * switches, that tests cover, is judged in streams of the test files that
 * cover them, each its own Pest run of those files, and is given its result
 * as its own run would give it. A mutant a stream cannot judge is left for
 * its own run.
 *
 * @internal
 */
final readonly class Streams
{
    /** Whether the gate asks this run to split its streams, and PHP can fork. */
    public static function areOn(): bool
    {
        return StreamSettings::fromEnvironment()->isOn()
            && Schemata::areOn()
            && Forking::isAvailable();
    }

    /**
     * Judges every mutant of the suite a stream can, giving each its result:
     * where the ledger names a likely killer, a first stream forks it at that
     * test alone, and a second, of the files that cover what the first left
     * undecided, at every covering test (ADR-0117).
     *
     * @param array<string, array<int, array<int, string>>> $coveredLines
     * @param list<string>                                    $arguments the arguments a mutant's own run is started with
     */
    public static function judge(MutationSuite $suite, array $coveredLines, array $arguments, int $processes): void
    {
        $sites = [];
        $covering = [];
        $byId = [];
        $settings = StreamSettings::fromEnvironment();
        $hints = $settings->killersFirst()
            ? KillerHints::of($suite, getenv(GateVariable::Order->value), Root::of(sprintf('%s', getcwd())))
            : KillerHints::none();

        foreach ($suite->repository->all() as $collection) {
            foreach ($collection->tests() as $test) {
                $switch = Schemata::switchOf($test->mutation);
                $covered = self::coveringOf($test, $coveredLines);

                if ($switch instanceof NotGiven || $covered === []) {
                    continue;
                }

                $mutant = $test->mutation->id;
                $sites[$mutant] = [...$switch, $hints->killerOf($mutant, $covered), self::variablesOf($test)];
                $covering[$mutant] = $covered;
                $byId[$mutant] = $test;
            }
        }

        if ($byId === []) {
            return;
        }

        $directory = sprintf('%s/mutation-gate-split-%s', sys_get_temp_dir(), uniqid());
        mkdir($directory, recursive: true);
        Schemata::leftIn($directory);
        $streaming = new Streaming($directory, $covering, $arguments, $processes, $settings->children());
        $verdicts = $streaming->pass(1, $sites, StreamVerdicts::none());
        $again = [];

        foreach ($sites as $mutant => [$key, $id, $hint, $variables]) {
            if ($hint !== '' && ! $verdicts->decides($mutant, $covering[$mutant])) {
                $again[$mutant] = [$key, $id, '', $variables];
            }
        }

        $verdicts = $again === []
            ? $verdicts
            : $streaming->pass(2, $again, $verdicts);

        foreach ($byId as $mutant => $test) {
            $verdicts->give($mutant, $test, $covering[$mutant], Facade::instance());
        }

        self::removed($directory);
    }

    /** The directory removed, with everything in it. */
    private static function removed(string $directory): void
    {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            if ($entry instanceof SplFileInfo && $entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink((string) $entry);
            }
        }

        rmdir($directory);
    }

    /**
     * The tests that cover a mutant, as Pest gathers them for its filter.
     *
     * @param  array<string, array<int, array<int, string>>> $coveredLines
     * @return list<string>
     */
    private static function coveringOf(MutationTest $test, array $coveredLines): array
    {
        $covering = [];
        $file = (string) $test->mutation->file->getRealPath();

        for ($line = $test->mutation->startLine; $line <= $test->mutation->endLine; $line++) {
            foreach ($coveredLines[$file][$line] ?? [] as $covered) {
                $covering[] = $covered;
            }
        }

        return array_values(array_unique($covering));
    }

    /** @return array<string, string> what a mutant's own run is told, which a child's subprocesses inherit */
    private static function variablesOf(MutationTest $test): array
    {
        return [
            Mutate::ENV_MUTATION_TESTING => (string) $test->mutation->file->getRealPath(),
            Mutate::ENV_MUTATION_FILE => $test->mutation->modifiedSourcePath,
            ...Schemata::variablesOf($test->mutation),
        ];
    }
}
