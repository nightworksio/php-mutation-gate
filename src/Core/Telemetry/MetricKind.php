<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Telemetry;

/**
 * How a metric's points add up: a gauge is a reading, a sum a count of this run's.
 *
 * @internal
 */
enum MetricKind
{
    case Gauge;
    case Sum;
}
