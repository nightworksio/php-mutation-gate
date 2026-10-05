<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use function count;

use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\ChangeKind;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\Judges;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\TestIds;

use function sprintf;

/**
 * The tests a changed source file can make fail (ADR-0020, decision 2): a
 * changed or deleted file reaches the tests that run any of its lines, as it
 * was and as it is, and those {@see ReadingTests} finds read what it
 * declares; a new file reaches the tests that name what it declares,
 * directly or through the support they use.
 */
final readonly class SourceTests
{
    private const string RUNS = '`%s` changed, and these tests run %d of its lines.';

    private const string SELECTED = '`%s` changed, and the runner selects this file to judge it.';

    private const string UNRUN = '`%s` changed, and no test runs it or reads what it declares.';

    private const string NAMED = '`%s` is new, and these tests name what it declares.';

    private const string UNNAMED = '`%s` is new, and no test names what it declares.';

    private const string UNTOLD = 'The tests that name what `%s` declares cannot be told, so every test is listed.';

    public function __construct(
        private TestPlaces $places,
        private Judges $judges,
        private CoverageMap $map,
        private ReadingTests $readers,
        private SupportUsers $users,
        private Sources $sources,
    ) {
    }

    /** The tests a change to a source file of a package so named reaches. */
    public function of(Change $change, string $package): AffectedTests
    {
        return $change->kind() === ChangeKind::Added
            ? $this->added($change, $package)
            : $this->changed($change, $package);
    }

    private function changed(Change $change, string $package): AffectedTests
    {
        $path = $change->path();
        $reached = $this->readers->of($change, $package);

        foreach (Paths::of($change->previousPath(), $path) as $file) {
            $reached = $this->running($reached, $file, $path);
        }

        return $reached->listsNone()
            ? $reached->leaving($path, Reason::that(sprintf(self::UNRUN, $path->value())))
            : $reached;
    }

    /** These tests, with the files that run a file as it was or is, each with its tests that run it. */
    private function running(AffectedTests $affected, Path $file, Path $changed): AffectedTests
    {
        $covering = $this->map->testsCoveringFile($file);

        foreach ($this->judges->of($file) as $test) {
            $tests = $this->places->in($test)->among($covering);
            $affected = count($tests) === 0
                ? $affected->wholly($test, Reason::that(sprintf(self::SELECTED, $changed->value())))
                : $affected->reaching(
                    $test,
                    $tests,
                    Reason::that(sprintf(self::RUNS, $changed->value(), $this->linesRunBy($file, $tests))),
                );
        }

        return $affected;
    }

    private function added(Change $change, string $package): AffectedTests
    {
        $path = $change->path();
        $affected = AffectedTests::none($this->places);
        $users = $this->users->ofChanged($change, $this->sources, $package);

        if ($users instanceof Reason) {
            return $affected->all(Reason::that(sprintf(self::UNTOLD, $path->value())));
        }

        foreach ($users as $test) {
            $affected = $affected->wholly($test, Reason::that(sprintf(self::NAMED, $path->value())));
        }

        return count($users) === 0
            ? $affected->leaving($path, Reason::that(sprintf(self::UNNAMED, $path->value())))
            : $affected;
    }

    /** How many lines of a file these tests run. */
    private function linesRunBy(Path $file, TestIds $tests): int
    {
        $lines = 0;

        foreach ($this->map->linesCovered($file) as $line) {
            $lines += count($this->map->testsCovering($file, $line)->among($tests)) > 0 ? 1 : 0;
        }

        return $lines;
    }
}
