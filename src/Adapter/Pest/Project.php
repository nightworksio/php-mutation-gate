<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function dirname;
use function file_exists;
use function glob;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_string;
use function mkdir;

use NightWorksIO\MutationGate\Adapter\Pest\Order\Plan;
use NightWorksIO\MutationGate\Adapter\Pest\Order\Seed;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Runner\Leftover;

use function realpath;
use function sprintf;
use function unlink;

/**
 * The project Pest runs in: its root, the directories its tests live in, the
 * directory the gate works in, and the one Composer installed its packages
 * in. Pest reports files by their real path, so the root is held as its real
 * path too.
 */
final readonly class Project
{
    private const string RESULTS = 'pest/results.jsonl';

    /** Where the plugin keeps each mutant's order, under the workspace. */
    private const string ORDER = 'order';

    /** Why a run cannot be told from an earlier one. */
    private const string STALE = 'An earlier run left %s or the map beside it, and the gate cannot remove them.';

    /** Why a run's orders cannot be told from an earlier run's. */
    private const string STALE_ORDER = 'An earlier run left orders in %s, and the gate cannot remove them.';

    private function __construct(
        private Root $root,
        private Paths $tests,
        private Path $workspace,
        private Path $vendor,
    ) {
    }

    /** The project at a root, as the runner spells it (owner: flows), held as its real path. */
    public static function at(string $root, Paths $tests, Path $workspace, Path $vendor): self
    {
        return new self(Root::of(self::real($root)), $tests, $workspace, $vendor);
    }

    /** The gate's directory, where the adapter keeps what it writes. */
    public function workspace(): string
    {
        return $this->root->at($this->workspace)->value();
    }

    /**
     * The same project in one of its directories, with its tests in these:
     * the gate's directory and the vendor directory are that directory's.
     */
    public function in(Path $directory, Paths $tests): self
    {
        return self::at($this->absolute($directory), $tests, $this->workspace, $this->vendor);
    }

    /** The root's real path, as Pest's coverage and the processes it starts take it. */
    public function root(): string
    {
        return $this->root->value();
    }

    /** @return Paths the directories the tests live in */
    public function tests(): Paths
    {
        return $this->tests;
    }

    /** The directory Composer installed the project's packages in, Pest's among them. */
    public function vendor(): Path
    {
        return $this->vendor;
    }

    /** Where a path of the project is on disk; an absolute path is where it says. */
    public function absolute(Path $path): string
    {
        return $this->root->at($path)->value();
    }

    /** A file on disk as the project spells it, or as it is where it lies outside the project. */
    public function relative(string $file): Path
    {
        return $this->root->relative($file);
    }

    /** A directory of the project on disk, made where it is not there yet. */
    public function directory(Path $path): string
    {
        $directory = $this->absolute($path);

        if (! is_dir($directory)) {
            mkdir($directory, recursive: true);
        }

        return $directory;
    }

    /**
     * The file the plugin writes a run's results to, with no earlier run's
     * results, map, list of mutants to make, mutated copies or error logs
     * left beside it, or why an earlier run's are still there.
     */
    public function freshResults(): string|CannotJudge
    {
        $results = $this->absolute($this->workspace->child(Path::of(self::RESULTS)));
        $this->directory(Path::of(dirname($results)));
        $copies = glob(Recorder::mutantBeside($results, '*'));
        $logs = glob(Recorder::everyErrorLogBeside($results));
        $beside = [Recorder::coverageBeside($results), OnlyList::beside($results), OpeningIssues::beside($results)];
        $beside = [...$beside, ...(is_array($copies) ? $copies : []), ...(is_array($logs) ? $logs : [])];

        return $this->without($results, ...$beside)
            ? $results
            : CannotJudge::because(sprintf(self::STALE, $results));
    }

    /**
     * A file of the adapter's own in the gate's directory, with its directory
     * made and no earlier run's copy of it left, or why one is still there.
     */
    public function fresh(string $name): string|CannotJudge
    {
        $file = sprintf('%s/%s', $this->absolute($this->workspace), $name);
        $this->directory(Path::of(dirname($file)));

        return $this->without($file) ? $file : Leftover::at($file);
    }

    /**
     * The directory the plugin writes each mutant's order to, holding no
     * earlier run's plan or orders, or why an earlier run's are still there.
     */
    public function freshOrder(): string|CannotJudge
    {
        $order = $this->directory($this->workspace->child(Path::of(self::ORDER)));
        $seeds = glob(sprintf('%s/*/%s', $order, Seed::HISTORY));

        return $this->without(Plan::in($order), ...(is_array($seeds) ? $seeds : []))
            ? $order
            : CannotJudge::because(sprintf(self::STALE_ORDER, $order));
    }

    /** Whether none of these files, nor a link in the place of one, is there once each that is has been removed. */
    public function without(string ...$files): bool
    {
        $gone = true;

        foreach ($files as $file) {
            if (is_file($file) || is_link($file)) {
                unlink($file);
            }

            $gone = $gone && ! file_exists($file) && ! is_link($file);
        }

        return $gone;
    }

    /** A directory as its real path where it is there, so that it compares with the real paths the runner reports. */
    private static function real(string $directory): string
    {
        $real = realpath($directory);

        return is_string($real) ? $real : $directory;
    }
}
