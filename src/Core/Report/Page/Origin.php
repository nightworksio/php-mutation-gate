<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report\Page;

use function array_key_exists;
use function implode;
use function mb_substr;

use NightWorksIO\MutationGate\Core\Report\JsonReport;
use NightWorksIO\MutationGate\Core\Report\ProvenanceJson;
use NightWorksIO\MutationGate\Core\Verdict\BaselineState;

use function sprintf;

/**
 * The HTML report's line of where its verdict comes from, read from the JSON
 * report's `provenance`: the gate's version, the commit judged, the runner
 * and the packages it drives, how long the run took, and how the baseline
 * file stands. A part the run could not tell is left out.
 *
 * @phpstan-import-type Report from JsonReport
 * @phpstan-import-type ProvenanceEntry from ProvenanceJson
 * @phpstan-import-type PackageEntry from ProvenanceJson
 */
final readonly class Origin
{
    /** How many characters of a commit's id the line shows, as git abbreviates one. */
    private const int SHORT = 12;

    private const string LINE = '<footer class="provenance"><ul>%s</ul></footer>';

    private const string GATE = 'mutation-gate <span title="%2$s">%1$s</span>';

    private const string COMMIT = 'commit <code title="%2$s">%1$s</code>';

    private const string RUNNER = '%s <span class="packages">%s</span>';

    private const string PACKAGE = '%s %s';

    private const string TOOK = 'took %s';

    private const string BASELINE = '<span class="baseline baseline--%s">%s</span>';

    /** What the line calls the baseline file where the verdict names none. */
    private const string UNNAMED = 'the baseline';

    private const string CURRENT = '%s is current';

    private const string STALE = '%s is stale: a score rose above its floor';

    private const string MISSING = '%s holds no floor for a set with a score';

    /** @param Report $report */
    public static function html(array $report): string
    {
        $provenance = $report['provenance'];
        $parts = [
            array_key_exists('gate', $provenance) ? self::gate($provenance['gate']) : '',
            array_key_exists('commit', $provenance) ? self::commit($provenance['commit']) : '',
            array_key_exists('runner', $provenance) ? self::runner($provenance['runner']) : '',
            array_key_exists('wallSeconds', $provenance)
                ? sprintf(self::TOOK, Html::seconds($provenance['wallSeconds']))
                : '',
            self::baseline($provenance['baseline']),
        ];

        return sprintf(self::LINE, Html::items($parts));
    }

    /** @param PackageEntry $gate */
    private static function gate(array $gate): string
    {
        return sprintf(self::GATE, Html::text($gate['version']), Html::text($gate['reference']));
    }

    private static function commit(string $commit): string
    {
        return sprintf(self::COMMIT, Html::text(mb_substr($commit, 0, self::SHORT)), Html::text($commit));
    }

    /** @param array{name: string, packages: list<PackageEntry>} $runner */
    private static function runner(array $runner): string
    {
        $packages = [];

        foreach ($runner['packages'] as $package) {
            $packages[] = Html::text(sprintf(self::PACKAGE, $package['package'], $package['version']));
        }

        return sprintf(self::RUNNER, Html::text($runner['name']), implode(', ', $packages));
    }

    /** @param array{file?: string, state: string} $baseline */
    private static function baseline(array $baseline): string
    {
        $state = BaselineState::from($baseline['state']);
        $file = array_key_exists('file', $baseline) ? $baseline['file'] : self::UNNAMED;
        $said = match ($state) {
            BaselineState::Current => self::CURRENT,
            BaselineState::Stale => self::STALE,
            BaselineState::Missing => self::MISSING,
        };

        return sprintf(self::BASELINE, $state->value, Html::text(sprintf($said, $file)));
    }
}
