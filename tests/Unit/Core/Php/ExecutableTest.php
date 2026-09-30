<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\Executable;

it('is one line coverage speaks for', function (): void {
    expect(Executable::line())->toEqual(Executable::line());
});
