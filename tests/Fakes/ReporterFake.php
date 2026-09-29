<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fakes;

use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\Reporter;

/** A reporter that keeps every verdict it was given. */
final class ReporterFake implements Reporter
{
    /** @var list<Verdict> */
    public private(set) array $reported = [];

    public function report(Verdict $verdict): Written
    {
        $this->reported[] = $verdict;

        return Written::to('memory');
    }
}
