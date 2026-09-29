<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\ExitCode;

it('ends a run with 0 when it passed, 1 when it failed and 2 when it could not judge', function (): void {
    expect(ExitCode::Passed->value)->toBe(0)
        ->and(ExitCode::Failed->value)->toBe(1)
        ->and(ExitCode::CannotJudge->value)->toBe(2);
});
