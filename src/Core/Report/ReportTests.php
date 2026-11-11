<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_key_exists;
use function is_array;

/** The `json` reporter's `tests`: which tests each mutant's entry lists (ADR-0112).
 *
 * @internal
 */
enum ReportTests: string
{
    /** Every list: the tests that cover it, judge it, killed it and fail unmutated. */
    case All = 'all';

    /** The tests that killed it and that fail unmutated; none that only cover or judge it. */
    case Killers = 'killers';

    /** None. */
    case None = 'none';

    /** The option that names it. */
    public const string OPTION = 'tests';

    /**
     * The keys of a mutant's entry it empties.
     *
     * @return list<string>
     */
    public function emptied(): array
    {
        return match ($this) {
            self::All => [],
            self::Killers => ['coveredBy', 'judgedBy'],
            self::None => ['coveredBy', 'judgedBy', 'killedBy', 'failingUnmutated'],
        };
    }

    /**
     * A report's data with each mutant's lists it empties emptied, where they are written.
     *
     * @param  array<string, mixed> $report
     * @return array<string, mixed>
     */
    public function trimmed(array $report): array
    {
        $emptied = $this->emptied();
        $mutants = $report['mutants'] ?? [];

        if ($emptied === [] || ! is_array($mutants)) {
            return $report;
        }

        foreach ($mutants as $at => $mutant) {
            foreach (is_array($mutant) ? $emptied : [] as $key) {
                if (array_key_exists($key, $mutant)) {
                    $mutants[$at][$key] = [];
                }
            }
        }

        return [...$report, 'mutants' => $mutants];
    }
}
