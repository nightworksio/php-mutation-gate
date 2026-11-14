<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Watch;

use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * The changes `outbreak serve` watches the files (ADR-0133, decision 7) and
 * the machine's power source (decision 8) for through the platform's
 * notifications, each counted in generations: each burst of changes of the
 * files, once it settled for `outbreak.serve.debounce`, is one more. An
 * answer that does not come in time, or any other, is no answer, and `watch`
 * looks at the files itself.
 *
 * @internal
 */
interface NoticesChanges
{
    /**
     * The generation once a change settled past `$since`, or once `$wait`
     * passed, these directories watched from now on where they are not the
     * ones watched already; why it watches nothing; or why it answers none.
     *
     * @param list<string> $directories each relative to the project's root
     */
    public function changed(array $directories, int $since, Seconds $wait): int|Unwatched|NotWritten;

    /**
     * The generation of the power source's changes once one came past `$since`, or once `$wait` passed; why it watches
     * none; or why it answers none.
     */
    public function powered(int $since, Seconds $wait): int|Unwatched|NotWritten;
}
