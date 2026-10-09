<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Recording\Verdicts;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('names the mutated copies the gate rejected beside the results, and keeps them once the file is there', function (): void {
    $file = Verdicts::write(Verdicts::beside(sprintf('%s/results.jsonl', Scratch::directory())), '/tmp/a', '/tmp/b');

    Verdicts::await($file, Seconds::of(1.0));

    expect($file)->toEndWith('/results.jsonl.verdicts')
        ->and(is_file(sprintf('%s.part', $file)))->toBeFalse()
        ->and([Verdicts::rejects('/tmp/a'), Verdicts::rejects('/tmp/b'), Verdicts::rejects('/tmp/c')])->toBe([true, true, false]);
});

it('rejects nothing where the gate rejected none', function (): void {
    $file = Verdicts::write(sprintf('%s/none.verdicts', Scratch::directory()));

    Verdicts::await($file, Seconds::of(1.0));

    expect(file_get_contents($file))->toBe('')
        ->and(Verdicts::rejects(''))->toBeFalse()
        ->and(Verdicts::rejects('/tmp/a'))->toBeFalse();
});

it('waits for the file as long as it is told, and rejects nothing where the gate never wrote it', function (): void {
    $started = hrtime(as_number: true);

    Verdicts::await(sprintf('%s/never.verdicts', Scratch::directory()), Seconds::of(0.2));

    expect((hrtime(as_number: true) - $started) / Seconds::NANOSECONDS)->toBeGreaterThanOrEqual(0.2)
        ->and(Verdicts::rejects(''))->toBeFalse();
});
