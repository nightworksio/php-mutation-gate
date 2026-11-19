<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

/**
 * What records the lines a stream's parent executes in one test
 * (ADR-0147): `outbreak.so`, where the stream observes `lines` and it holds
 * PHP's executor, or pcov.
 *
 * @internal
 */
enum LineRecorder
{
    case Outbreak;
    case Pcov;
}
