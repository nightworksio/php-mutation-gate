<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Written;

it('says where it wrote', function (): void {
    expect(Written::to('build/mutation.sarif')->where())->toBe('build/mutation.sarif');
});

it('says where it was written, as a command prints it', function (): void {
    expect(Written::to('.mutation-gate/plan.json')->said())->toBe('Wrote .mutation-gate/plan.json.');
});
