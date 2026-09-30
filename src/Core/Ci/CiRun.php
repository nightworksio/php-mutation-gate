<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

use function mb_strlen;
use function mb_substr;
use function str_starts_with;

/**
 * A CI run as an alert names it: the repository as owner/name, the full ref
 * it ran on, the full commit, and the link to the run (ADR-0016, decision 12).
 */
final readonly class CiRun
{
    private const string HEADS = 'refs/heads/';

    private function __construct(
        private string $repository,
        private string $ref,
        private string $commit,
        private string $url,
    ) {
    }

    public static function of(string $repository, string $ref, string $commit, string $url): self
    {
        return new self($repository, $ref, $commit, $url);
    }

    /** The repository, as `owner/name`. */
    public function repository(): string
    {
        return $this->repository;
    }

    /** The full ref, as `refs/heads/main`. */
    public function ref(): string
    {
        return $this->ref;
    }

    /** The ref as a reader says it: a branch by its name, anything else as it is. */
    public function refName(): string
    {
        return str_starts_with($this->ref, self::HEADS) ? mb_substr($this->ref, mb_strlen(self::HEADS)) : $this->ref;
    }

    /** The full commit SHA. */
    public function commit(): string
    {
        return $this->commit;
    }

    public function url(): string
    {
        return $this->url;
    }
}
