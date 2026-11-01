<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Bitbucket;

use NightWorksIO\MutationGate\Core\Cluster\Cluster;
use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\GateCommand;
use NightWorksIO\MutationGate\Core\Report\AnnotationText;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;

use function sprintf;

/**
 * One mutant counted as not killed, or one cluster of survivors at its first member, as a Code Insights annotation
 * of type `BUG` (ADR-0024): at its file and line, `HIGH` in a set that failed and `MEDIUM` elsewhere, its summary
 * the gate's judgement and the mutator, and its details the hint and the command that reproduces it, or a
 * cluster's stub, each one plain line cut to fit.
 */
final readonly class InsightAnnotation
{
    /** The most characters of an annotation's summary. */
    private const int SUMMARY = 450;

    /** The most characters of an annotation's details. */
    private const int DETAILS = 2_000;

    private const string ID = 'mutation-gate-%s';

    /** The annotation of a mutant or a cluster, in a set that failed or not. */
    public static function of(JudgedMutant|Cluster $item, bool $failing, GateCommand $gate): Json
    {
        $mutant = ($item instanceof Cluster ? $item->representative() : $item)->mutant();

        return Json::object(
            Member::of('external_id', sprintf(self::ID, $mutant->id()->value())),
            Member::of('annotation_type', 'BUG'),
            Member::of('result', 'FAILED'),
            Member::of('severity', $failing ? 'HIGH' : 'MEDIUM'),
            Member::of('path', $mutant->location()->file()->value()),
            Member::of('line', $mutant->location()->start()->number()),
            Member::of('summary', Fit::line(Fit::plain(AnnotationText::title($item)), self::SUMMARY)),
            Member::of('details', Fit::line(Fit::plain(AnnotationText::message($item, $gate)), self::DETAILS)),
        );
    }
}
