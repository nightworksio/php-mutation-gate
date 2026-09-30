<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Written;

it('says where it wrote', function (): void {
    expect(Written::to('build/mutation.sarif')->where())->toBe('build/mutation.sarif');
});

it('says where it was written, as a command prints it', function (): void {
    expect(Written::to('.mutation-gate/plan.json')->said())->toBe('Wrote .mutation-gate/plan.json.');
});

it('says what the writer notes of what it wrote, after where', function (): void {
    expect(Written::noting('ledger.json.gz', 'It keeps the newest 3 of 4 proofs.')->said())
        ->toBe('Wrote ledger.json.gz. It keeps the newest 3 of 4 proofs.')
        ->and(Written::noting('ledger.json.gz', 'Kept.')->where())->toBe('ledger.json.gz');
});
