<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Tests\Support\DoctorRuns;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

$makeHere = static fn(): string => (string) getcwd();

afterEach(function () use ($makeHere): void {
    $here = $makeHere();

    chdir($here);
    Scratch::sweep();
});

it('cannot judge a format it does not write', function (): void {
    expect(DoctorRuns::over('tests/Fixtures/Projects/Library', ['--format' => 'xml']))
        ->toBe([2, '', "--format is xml; doctor writes text or json.\n"]);
});
