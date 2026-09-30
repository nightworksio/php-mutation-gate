<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

use function array_any;

use NightWorksIO\MutationGate\Core\Ci\Definitions;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

use function str_starts_with;

/**
 * The files outside the test directories that no key holds: the gate's config
 * file, whose settings that affect results are in the key already; the
 * baseline, which only holds floors; every CI definition, of which those that
 * run the gate are in the key as they run; the gate's own files, whatever
 * `.gitignore` says, since the ledger changes after every run; and what
 * `proofs.ignore` matches. None of them leaves out a file that defines the
 * runner: every key reads those.
 */
final readonly class Exceptions
{
    /** Where the gate keeps its own files wherever nothing else is configured. */
    private const string OWN = '.mutation-gate';

    private function __construct(
        private Path|Absent $config,
        private Path $baseline,
        private Ignored $ignored,
        private Paths $definitions,
        private Paths $own,
    ) {
    }

    /**
     * @param Path|Absent $config      the config file, or none under zero-config
     * @param Paths       $definitions the files that define the runner, which no exception leaves out
     */
    public static function of(Path|Absent $config, Path $baseline, Ignored $ignored, Paths $definitions): self
    {
        return new self($config, $baseline, $ignored, $definitions, Paths::of(Path::of(self::OWN)));
    }

    /**
     * These exceptions, and a file or directory the gate writes: the directory
     * store's path, the `--publish-dir`, or a report's path.
     */
    public function andWritten(Path $path): self
    {
        return new self($this->config, $this->baseline, $this->ignored, $this->definitions, $this->own->with($path));
    }

    /** Whether a key leaves a file out; never one that defines the runner, whatever `proofs.ignore` says. */
    public function leaveOut(Path $path): bool
    {
        return ! $this->definitions->has($path)
            && (
                $this->config instanceof Path && $path->equals($this->config)
                || $path->equals($this->baseline)
                || $this->ignored->matches($path)
                || $this->isCiDefinition($path)
                || $this->isOwn($path)
            );
    }

    /**
     * A warning for each `proofs.ignore` glob that matches a file among
     * these that defines the runner, which every key reads all the same.
     */
    public function overruled(Paths $files): Warnings
    {
        $warnings = Warnings::none();

        foreach ($files as $file) {
            $overruled = $this->definitions->has($file) ? $this->ignored->overruledFor($file) : Warnings::none();
            $warnings = Warnings::of(...$warnings, ...$overruled);
        }

        return $warnings;
    }

    private function isOwn(Path $path): bool
    {
        foreach ($this->own as $own) {
            if ($path->within($own)) {
                return true;
            }
        }

        return false;
    }

    private function isCiDefinition(Path $path): bool
    {
        return array_any(
            Definitions::PLACES,
            static fn(string $where): bool => $path->value() === $where || str_starts_with($path->value(), $where),
        );
    }
}
