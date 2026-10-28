<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Mutator\Engine\Change;
use NightWorksIO\MutationGate\Mutator\Engine\Offered;
use NightWorksIO\MutationGate\Mutator\Engine\Source;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinus;
use NightWorksIO\MutationGate\Tests\Support\Mutators\RemoveEcho;

it('makes each mutator\'s changes however the mutators are keyed', function (): void {
    $source = Source::parse("<?php\necho 1 + 2;\n");
    $changes = $source instanceof Source
        ? $source->changes(Offered::Everywhere, ...['plus' => new PlusToMinus(), 'echo' => new RemoveEcho()])
        : [];

    expect(array_map(static fn(Change $change): string => $change->edit()->mutated(), [...$changes]))
        ->toBe(["<?php\necho 1 - 2;\n", "<?php\n"]);
});
