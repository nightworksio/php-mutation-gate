<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use NightWorksIO\MutationGate\Core\Cluster\Cluster;
use NightWorksIO\MutationGate\Core\GateCommand;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;

use function sprintf;

/**
 * What a line annotation says of a mutant counted as not killed, or of a cluster of survivors at its first member,
 * on whichever forge shows it (ADR-0009, ADR-0022): its title, the gate's judgement and the mutator, or the
 * cluster's size; and its message, the hint and the command that reproduces it, or the cluster's hint and stub.
 */
final readonly class AnnotationText
{
    private const string MUTANT = 'Mutant %s: %s';

    private const string CLUSTER = 'Mutant cluster: %s';

    private const string REPRODUCE = '%s Reproduce: %s';

    private const string STUB = '%s Stub: %s';

    public static function title(JudgedMutant|Cluster $item): string
    {
        return $item instanceof Cluster
            ? sprintf(self::CLUSTER, ClusterText::size($item))
            : sprintf(self::MUTANT, Label::of($item->judgement()), Mutator::short($item->mutant()->mutator()));
    }

    public static function message(JudgedMutant|Cluster $item, GateCommand $gate): string
    {
        return $item instanceof Cluster
            ? sprintf(self::STUB, ClusterText::hint($item), $item->stub($gate))
            : sprintf(self::REPRODUCE, MutantText::hinted($item), $item->reproduce($gate));
    }
}
