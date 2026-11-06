<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Recheck;

use function array_map;
use function intval;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\WholeNumber;

use function range;
use function sprintf;

/**
 * One job's share of a re-check of the last run's survivors shared among
 * `of` jobs, counted from 1 (ADR-0106): of the files in the order taken,
 * every `of`-th, from the `share`-th, so each share keeps the order.
 *
 * @internal
 */
final readonly class ShareOf
{
    private const string NOT_A_SHARE = '--share=%s --of=%s names no share: give whole numbers, the share at most --of.';

    private const string NOT_JOBS = '--of=%s names no number of jobs: give a whole number of 1 or more.';

    private function __construct(private int $share, private int $of)
    {
    }

    /** The share `--share` and `--of` name, or why they name none. */
    public static function parse(string $share, string $of): self|CannotJudge
    {
        return WholeNumber::isPositive($share) && WholeNumber::isPositive($of) && intval($share) <= intval($of)
            ? new self(intval($share), intval($of))
            : CannotJudge::because(sprintf(self::NOT_A_SHARE, $share, $of));
    }

    /** @return list<self>|CannotJudge every share of the jobs `--of` names, or why it names none */
    public static function every(string $of): array|CannotJudge
    {
        return WholeNumber::isPositive($of)
            ? array_map(static fn(int $share): self => new self($share, intval($of)), range(1, intval($of)))
            : CannotJudge::because(sprintf(self::NOT_JOBS, $of));
    }

    /** The whole re-check, one job's alone. */
    public static function whole(): self
    {
        return new self(1, 1);
    }

    public function share(): int
    {
        return $this->share;
    }

    /** Whether the file at this place in the order taken, counted from 0, falls to this share. */
    public function takes(int $place): bool
    {
        return $place % $this->of === $this->share - 1;
    }
}
