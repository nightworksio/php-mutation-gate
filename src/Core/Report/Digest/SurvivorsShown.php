<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report\Digest;

use function array_slice;
use function count;
use function ctype_digit;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\Visible;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Report\Page\Survivors;
use NightWorksIO\MutationGate\Core\Report\ProblemsShown;

use function sprintf;

/**
 * How many survivors the digest's overview lists: as many as the HTML
 * report's overview names, by default, as many as `--survivors=<n>` asks
 * for, or every one with `--survivors=all`, the word `--only` takes too.
 */
final readonly class SurvivorsShown
{
    private const string UNKNOWN = '--survivors is %s; it takes a whole number, or all.';

    private function __construct(private int|NotGiven $most)
    {
    }

    /** As many as the HTML report's overview names. */
    public static function first(): self
    {
        return new self(Survivors::SHOWN);
    }

    /** How many `--survivors` asks for; or why it cannot be read. */
    public static function asked(string $written): self|CannotJudge
    {
        return match (true) {
            $written === ProblemsShown::All->value => new self(NotGiven::value()),
            ctype_digit($written) => new self((int) $written),
            default => CannotJudge::because(sprintf(self::UNKNOWN, Visible::line($written))),
        };
    }

    /**
     * @template T
     *
     * @param  list<T> $survivors
     * @return list<T> those the overview lists
     */
    public function of(array $survivors): array
    {
        return $this->most instanceof NotGiven ? $survivors : array_slice($survivors, 0, $this->most);
    }

    /**
     * How many of these the overview leaves out.
     *
     * @template T
     *
     * @param list<T> $survivors
     */
    public function leftOut(array $survivors): int
    {
        return count($survivors) - count($this->of($survivors));
    }
}
