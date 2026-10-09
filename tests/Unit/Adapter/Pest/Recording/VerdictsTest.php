<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Recording\Verdicts;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('names the mutated copies the gate rejected beside the results, and keeps them once the file is there', function (): void {
    $file = Verdicts::write(Verdicts::beside(sprintf('%s/results.jsonl', Scratch::directory())), '/tmp/a', '/tmp/b');

    Verdicts::await($file);

    expect($file)->toEndWith('/results.jsonl.verdicts')
        ->and([Verdicts::rejects('/tmp/a'), Verdicts::rejects('/tmp/b'), Verdicts::rejects('/tmp/c')])->toBe([true, true, false]);
});

it('rejects nothing where the gate rejected none', function (): void {
    $file = Verdicts::write(sprintf('%s/none.verdicts', Scratch::directory()));

    Verdicts::await($file);

    expect(file_get_contents($file))->toBe('')
        ->and(Verdicts::rejects(''))->toBeFalse()
        ->and(Verdicts::rejects('/tmp/a'))->toBeFalse();
});
