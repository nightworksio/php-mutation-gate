<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

/**
 * Where the current test runs, once a stream that boots the application
 * once was asked to fork it (ADR-0117): taken by the test's own process,
 * forked for it, which runs it; waited for by the stream's, which reads its
 * kills and skips the test; or kept where the stream cannot fork here, and
 * the test boots its own application.
 *
 * @internal
 */
enum TestHanding
{
    case Taken;
    case Waited;
    case Kept;
}
