<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use function array_values;

use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Php\Codebase;
use NightWorksIO\MutationGate\Core\Php\PhpFile;
use NightWorksIO\MutationGate\Core\Php\Site;
use NightWorksIO\MutationGate\Core\Php\Source;
use NightWorksIO\MutationGate\Core\Php\Symbol;
use NightWorksIO\MutationGate\Core\Php\SymbolAt;

use function sprintf;

/**
 * The tests that read what a changed source file declares where coverage
 * cannot see it run: a class or interface constant, an enum case, a
 * property default or a parameter default, as the file is and as it was,
 * found by ADR-0004 decision 8's scan. A test that reads one is listed
 * whole; a read in source lists the tests that run its line; a read in
 * test support lists the tests that use it; and a read no name can show
 * lists every test.
 */
final readonly class ReadingTests
{
    private const string READS = '`%s` declares %s, which these tests read.';

    private const string READS_ON = '`%s` declares %s, read on line %d of `%s`, which these tests run.';

    private const string READS_IN = '`%s` declares %s, read in `%s`, test support these tests use.';

    private const string UNSURE
        = '`%s` declares %s, which code may read where no name says so, so every test is listed.';

    public function __construct(
        private TestPlaces $places,
        private CoverageMap $map,
        private Codebase $codebase,
        private SupportUsers $users,
        private Sources $sources,
    ) {
    }

    /** The tests that read what a change to a source file of a package so named declares. */
    public function of(Change $change, string $package): AffectedTests
    {
        $affected = AffectedTests::none($this->places);

        foreach ($this->symbolsOf($change) as $symbol) {
            $affected = $this->reading($affected, $symbol, $change->path(), $package);
        }

        return $affected;
    }

    private function reading(AffectedTests $affected, Symbol $symbol, Path $changed, string $package): AffectedTests
    {
        $references = $this->codebase->references($symbol);
        $declares = [$changed->value(), $symbol->described()];

        if ($references->isAmbiguous()) {
            return $affected->all(Reason::that(sprintf(self::UNSURE, ...$declares)));
        }

        foreach ($references->sites() as $site) {
            $affected = $site->isInTest()
                ? $this->readInTests($affected, $site->file(), $declares, $package)
                : $this->readInSource($affected, $site, $declares);
        }

        return $affected;
    }

    /** @param array{string, string} $declares the changed file and the symbol, as a reason names them */
    private function readInSource(AffectedTests $affected, Site $site, array $declares): AffectedTests
    {
        $file = $site->file();
        $line = $site->line();
        $why = Reason::that(sprintf(self::READS_ON, ...[...$declares, $line->number(), $file->value()]));

        foreach ($this->places->holding($this->map->testsCovering($file, $line, $line)) as [$test, $tests]) {
            $affected = $affected->reaching($test, $tests, $why);
        }

        return $affected;
    }

    /**
     * These, with a test file under the test directories that reads a symbol,
     * or the tests that use it where it is support.
     *
     * @param array{string, string} $declares
     */
    private function readInTests(AffectedTests $affected, Path $file, array $declares, string $package): AffectedTests
    {
        if ($this->places->files()->has($file)) {
            return $affected->wholly($file, Reason::that(sprintf(self::READS, ...$declares)));
        }

        $contents = $this->sources->now($file);
        $users = $contents instanceof Contents
            ? $this->users->of($file, PhpFile::read($contents)->declares(), $package)
            : Paths::none();

        if (! $contents instanceof Contents || $users instanceof Reason) {
            return $affected->all(Reason::that(sprintf(Affecting::UNTOLD, $file->value())));
        }

        $why = Reason::that(sprintf(self::READS_IN, ...[...$declares, $file->value()]));

        foreach ($users as $test) {
            $affected = $affected->wholly($test, $why);
        }

        return $affected;
    }

    /**
     * Each symbol the file declares that coverage cannot see run, as it is and
     * as it was, each once.
     *
     * @return list<Symbol>
     */
    private function symbolsOf(Change $change): array
    {
        $symbols = [];
        $versions = [
            [$change->path(), $this->sources->now($change->path())],
            [$change->previousPath(), $this->sources->before($change->previousPath())],
        ];

        foreach ($versions as [$path, $contents]) {
            $symbols += $contents instanceof Contents
                ? $this->declaredIn(Source::read($path, $contents, test: false))
                : [];
        }

        return array_values($symbols);
    }

    /** @return array<string, Symbol> each symbol a source declares that coverage cannot see run, by its description */
    private function declaredIn(Source $source): array
    {
        $at = SymbolAt::in($source->tokens(), $source->shape());
        $symbols = [];

        for ($token = 0; $token < $source->tokens()->count(); ++$token) {
            $symbol = $at->token($token);
            $symbols += $symbol instanceof Symbol ? [$symbol->described() => $symbol] : [];
        }

        return $symbols;
    }
}
