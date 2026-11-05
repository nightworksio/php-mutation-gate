<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Warm;

/**
 * Where a process stands towards a booted warm worker of the verification
 * tail (ADR-0075): one that never booted the project, a worker booting it, or
 * a child forked from one that did.
 *
 * @internal
 */
enum BootStage: string
{
    /** A process that boots the project itself, as a fresh one does. */
    case Unbooted = 'unbooted';

    /** A worker booting the project, every test file loaded, before it forks. */
    case Booting = 'booting';

    /** A child forked from a worker that booted the project. */
    case Forked = 'forked';
}
