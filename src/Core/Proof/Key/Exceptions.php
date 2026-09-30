<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

use function array_any;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

use function str_starts_with;

/**
 * The files outside the test directories that no key holds: the gate's config
 * file, whose settings that affect results are in the key already; the
 * baseline, which only holds floors; every CI definition, of which those that
 * run the gate are in the key as they run; the gate's own files, whatever
 * `.gitignore` says, since the ledger changes after every run; and what
 * `proofs.ignore` matches.
 */
final readonly class Exceptions
{
    /** Where the CIs the gate knows keep their definitions: a file, or a directory ending in `/`. */
    private const array CI_DEFINITIONS = ['.github/workflows/', '.gitlab-ci.yml', '.buildkite/', '.circleci/'];

    /** Where the gate keeps its own files wherever nothing else is configured. */
    private const string OWN = '.mutation-gate';

    private function __construct(
        private Path $config,
        private Path $baseline,
        private Ignored $ignored,
        private Paths $own,
    ) {
    }

    public static function of(Path $config, Path $baseline, Ignored $ignored): self
    {
        return new self($config, $baseline, $ignored, Paths::of(Path::of(self::OWN)));
    }

    /**
     * These exceptions, and a file or directory the gate writes: the directory
     * store's path, the `--publish-dir`, or a report's path.
     */
    public function andWritten(Path $path): self
    {
        return new self($this->config, $this->baseline, $this->ignored, $this->own->with($path));
    }

    public function leaveOut(Path $path): bool
    {
        return $path->equals($this->config)
            || $path->equals($this->baseline)
            || $this->ignored->matches($path)
            || $this->isCiDefinition($path)
            || $this->isOwn($path);
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
            self::CI_DEFINITIONS,
            static fn(string $where): bool => $path->value() === $where || str_starts_with($path->value(), $where),
        );
    }
}
