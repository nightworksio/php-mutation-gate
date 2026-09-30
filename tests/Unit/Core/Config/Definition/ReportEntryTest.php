<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition\Builtins;
use NightWorksIO\MutationGate\Core\Config\Definition\ReportEntry;

it('says a report is an object with use and path', function (): void {
    expect(ReportEntry::choosing(Builtins::of([]), [])->expected())->toBe('an object with use and path');
});
