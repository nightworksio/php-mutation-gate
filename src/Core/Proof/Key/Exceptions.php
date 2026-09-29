<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

use function array_any;

use NightWorksIO\MutationGate\Core\File\Path;

use function str_starts_with;

/**
 * The files outside the test directories that no key holds: the gate's config
 * file, whose settings that affect results are in the key already; the
 * baseline, which only holds floors; every CI definition, of which the one
 * that runs the gate is in the key as it runs; and what `proofs.ignore`
 * matches.
 */
final readonly class Exceptions
{
    /** Where the CIs the gate knows keep their definitions: a file, or a directory ending in `/`. */
    private const array CI_DEFINITIONS = ['.github/workflows/', '.gitlab-ci.yml', '.buildkite/', '.circleci/'];

    private function __construct(private Path $config, private Path $baseline, private Ignored $ignored)
    {
    }

    public static function of(Path $config, Path $baseline, Ignored $ignored): self
    {
        return new self($config, $baseline, $ignored);
    }

    public function leaveOut(Path $path): bool
    {
        return $path->equals($this->config)
            || $path->equals($this->baseline)
            || $this->ignored->matches($path)
            || self::isCiDefinition($path);
    }

    private static function isCiDefinition(Path $path): bool
    {
        return array_any(
            self::CI_DEFINITIONS,
            static fn(string $where): bool => $path->value() === $where || str_starts_with($path->value(), $where),
        );
    }
}
