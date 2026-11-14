<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use NightWorksIO\MutationGate\Core\Coverage\Judges;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

/**
 * What the gate asks Outbreak's static graph (ADR-0132): the project's PHP
 * files that are not test files, its test files, the files every test loads
 * before it runs, the text of each file read from the question in place of
 * the disk, such as a deleted file's last text, the files whose reaching
 * tests, joined files and named files are asked for, and the files the
 * coverage map shows each test file running, which the graph is checked by.
 *
 * @internal
 */
final readonly class GraphQuestion
{
    /**
     * @param array<string, Contents> $given each file's text, by its path
     * @param array<string, Paths>    $map   the files each test file runs, by its path
     */
    private function __construct(
        private Paths $sources,
        private Paths $tests,
        private Paths $always,
        private array $given,
        private Paths $reaching,
        private Paths $joined,
        private Paths $named,
        private array $map,
    ) {
    }

    /** A question of the graph of these files, asking nothing yet. */
    public static function over(Paths $sources, Paths $tests, Paths $always): self
    {
        return new self($sources, $tests, $always, [], Paths::none(), Paths::none(), Paths::none(), []);
    }

    /** The same, with a file the disk no longer holds, by its last text, as a test file or not. */
    public function given(Path $path, Contents $text, bool $test): self
    {
        return new self(
            $test ? $this->sources : $this->sources->with($path),
            $test ? $this->tests->with($path) : $this->tests,
            $this->always,
            [...$this->given, $path->value() => $text],
            $this->reaching,
            $this->joined,
            $this->named,
            $this->map,
        );
    }

    /** The same, asking which test files reach these files, and which files the tests that reach them reach. */
    public function about(Paths $files): self
    {
        return new self(
            $this->sources,
            $this->tests,
            $this->always,
            $this->given,
            $this->reaching->and($files),
            $this->joined->and($files),
            $this->named,
            $this->map,
        );
    }

    /** The same, asking which files these files name. */
    public function naming(Paths $files): self
    {
        return new self(
            $this->sources,
            $this->tests,
            $this->always,
            $this->given,
            $this->reaching,
            $this->joined,
            $this->named->and($files),
            $this->map,
        );
    }

    /** The same, checked by what the coverage map shows each test file running. */
    public function checkedBy(Judges $map): self
    {
        $runs = [];

        foreach ($map->testFiles() as $test) {
            $runs[$test->value()] = $map->filesRunBy($test);
        }

        return new self(
            $this->sources,
            $this->tests,
            $this->always,
            $this->given,
            $this->reaching,
            $this->joined,
            $this->named,
            $runs,
        );
    }

    /** Whether it asks anything. */
    public function asks(): bool
    {
        return $this->reaching->count() + $this->joined->count() + $this->named->count() > 0;
    }

    public function sources(): Paths
    {
        return $this->sources;
    }

    public function tests(): Paths
    {
        return $this->tests;
    }

    public function always(): Paths
    {
        return $this->always;
    }

    /** @return array<string, Contents> */
    public function texts(): array
    {
        return $this->given;
    }

    public function reaching(): Paths
    {
        return $this->reaching;
    }

    public function joined(): Paths
    {
        return $this->joined;
    }

    public function named(): Paths
    {
        return $this->named;
    }

    /** @return array<string, Paths> */
    public function map(): array
    {
        return $this->map;
    }
}
