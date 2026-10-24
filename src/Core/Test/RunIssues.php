<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

/**
 * Where a mutant's own run, once its tests have run, reads the tests its
 * issues name as its killers (see IssueKillers).
 */
interface RunIssues
{
    /** @return list<string> the tests, by id, each once */
    public function killers(): array;
}
