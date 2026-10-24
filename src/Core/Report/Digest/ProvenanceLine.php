<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report\Digest;

use function implode;
use function mb_substr;

use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Report\Page\Origin;

use function round;
use function sprintf;

/**
 * The digest's line of where its verdict comes from, read from the JSON
 * report's `provenance`: the gate's version and reference, the commit
 * judged, the runner and the packages it drives, how long the run took in
 * seconds, and how the baseline file stands. A part the run could not tell
 * is left out.
 */
final readonly class ProvenanceLine
{
    private const string LINE = 'prov %s';

    private const string GATE = 'gate=%s@%s';

    private const string COMMIT = 'commit=%s';

    private const string RUNNER = 'runner=%s';

    private const string PACKAGE = '%s@%s';

    private const string TOOK = 'took=%ds';

    private const string BASELINE = 'baseline=%s';

    /** @throws NotInShape */
    public static function of(Node $provenance): string
    {
        $gate = $provenance->field('gate');
        $commit = $provenance->field('commit');
        $took = $provenance->field('wallSeconds');

        $parts = [
            ...$gate->isPresent()
                ? [sprintf(self::GATE, $gate->field('version')->text(), $gate->field('reference')->text())]
                : [],
            ...$commit->isPresent() ? [sprintf(self::COMMIT, mb_substr($commit->text(), 0, Origin::SHORT))] : [],
            ...self::runner($provenance->field('runner')),
            ...$took->isPresent() ? [sprintf(self::TOOK, round($took->number()))] : [],
            sprintf(self::BASELINE, $provenance->field('baseline')->field('state')->text()),
        ];

        return sprintf(self::LINE, implode(' ', $parts));
    }

    /**
     * @return list<string> the runner, then each package it drives
     *
     * @throws NotInShape
     */
    private static function runner(Node $runner): array
    {
        if (! $runner->isPresent()) {
            return [];
        }

        $said = [sprintf(self::RUNNER, $runner->field('name')->text())];

        foreach ($runner->field('packages')->items() as $package) {
            $said[] = sprintf(self::PACKAGE, $package->field('package')->text(), $package->field('version')->text());
        }

        return $said;
    }
}
