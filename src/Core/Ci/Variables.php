<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

use function array_key_exists;

/** The environment variables a CI set for a job, as the adapter that read them hands them on. */
final readonly class Variables
{
    /** Set to `true` by GitHub Actions in every job it runs. */
    public const string GITHUB_ACTIONS = 'GITHUB_ACTIONS';

    /** Set by every supported CI, and unset on a developer's machine. */
    private const string CI = 'CI';

    /** @param array<string, string> $values by name */
    private function __construct(private array $values)
    {
    }

    /** @param array<string, string> $values by name */
    public static function of(array $values): self
    {
        return new self($values);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->values) && $this->values[$name] !== '';
    }

    /** Whether the run is in CI, as every supported CI says by setting `CI`. */
    public function inCi(): bool
    {
        return $this->has(self::CI);
    }

    /** Whether GitHub Actions runs the job. */
    public function onGitHubActions(): bool
    {
        return $this->valueOf(self::GITHUB_ACTIONS) === 'true';
    }

    /** A variable's value, and nothing where it is not set. */
    public function valueOf(string $name): string
    {
        return array_key_exists($name, $this->values) ? $this->values[$name] : '';
    }
}
