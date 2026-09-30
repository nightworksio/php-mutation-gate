<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Written;

it('says where it wrote', function (): void {
    expect(Written::to('build/mutation.sarif')->where())->toBe('build/mutation.sarif');
});

it('says it printed on the console', function (): void {
    expect(Written::toTheConsole()->said())->toBe('Wrote the console.')
        ->and(Written::toTheConsole())->toEqual(Written::to('the console'));
});

it('says where it was written, as a command prints it', function (): void {
    expect(Written::to('.mutation-gate/plan.json')->said())->toBe('Wrote .mutation-gate/plan.json.');
});

it('says what the writer notes of what it wrote, after where', function (): void {
    expect(Written::noting('ledger.json.gz', 'It keeps the newest 3 of 4 proofs.')->said())
        ->toBe('Wrote ledger.json.gz. It keeps the newest 3 of 4 proofs.')
        ->and(Written::noting('ledger.json.gz', 'Kept.')->where())->toBe('ledger.json.gz');
});

it('is written where a write answered with the bytes it wrote, and says why not where it answered false', function (): void {
    expect(Written::attempted('plan.json', 12))->toEqual(Written::to('plan.json'))
        ->and(Written::attempted('plan.json', 0))->toEqual(Written::to('plan.json'))
        ->and(Written::attempted('plan.json', wrote: false))->toEqual(CannotJudge::because('plan.json could not be written.'))
        ->and(Written::failedAt('.git/hooks/pre-push'))->toEqual(CannotJudge::because('.git/hooks/pre-push could not be written.'));
});
