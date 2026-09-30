<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\Unreported;

it('stands for a line the runner does not report', function (): void {
    expect(Unreported::line())->toBeInstanceOf(Unreported::class);
});

it('stands for a reason the runner does not give', function (): void {
    expect(Unreported::reason())->toBeInstanceOf(Unreported::class);
});

it('stands for the rejection of a mutant no static analyser killed', function (): void {
    expect(Unreported::rejection())->toBeInstanceOf(Unreported::class);
});
