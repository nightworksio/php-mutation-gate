<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;
use function array_sum;
use function intval;

use NightWorksIO\MutationGate\Core\CannotJudge;

use function preg_match;
use function preg_match_all;
use function sprintf;

/**
 * The counts on Pest's own summary line, `Mutations: 1 untested, 2 uncovered,
 * 1 pending, 1 timeout, 4 tested`, by the status each names. Pest leaves out a
 * count of 0, and calls a mutant with no result pending.
 */
final readonly class Summary
{
    private const string LINE = '/^\s*Mutations:(?<counts>.*)$/m';

    private const string COUNT = '/(?<count>\d+) (?<status>untested|uncovered|pending|timeout|tested)\b/';

    /** @param array<string, int> $counts by the status the plugin records */
    private function __construct(private array $counts)
    {
    }

    public static function in(string $output): self|CannotJudge
    {
        if (preg_match(self::LINE, $output, $line) !== 1) {
            return CannotJudge::because(sprintf(
                "Pest printed no Mutations: summary, so its mutation run did not finish. Pest said:\n%s",
                $output,
            ));
        }

        preg_match_all(self::COUNT, $line['counts'], $found, PREG_SET_ORDER);
        $counts = [];

        foreach ($found as $count) {
            $counts[$count['status'] === 'pending' ? 'none' : $count['status']] = intval($count['count']);
        }

        return new self($counts);
    }

    /** How many mutants the summary gives a status the plugin records: tested, untested, uncovered, timeout or none. */
    public function count(string $status): int
    {
        return array_key_exists($status, $this->counts) ? $this->counts[$status] : 0;
    }

    public function total(): int
    {
        return array_sum($this->counts);
    }
}
