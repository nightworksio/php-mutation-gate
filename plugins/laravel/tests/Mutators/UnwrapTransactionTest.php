<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateLaravel\Mutators\UnwrapTransaction;
use PhpParser\Node\Stmt\Nop;

it('is named in the laravel set, an unwrap, untagged, with its own hint', function (): void {
    $mutator = new UnwrapTransaction();

    expect($mutator->name()->value())->toBe('laravel/UnwrapTransaction')
        ->and($mutator->family())->toBe(MutatorFamily::Unwrap)
        ->and($mutator->tags())->toEqual(Tags::none())
        ->and($mutator->hint())->toEqual(Hint::that('No test makes the transaction fail and checks that nothing it did was kept.'));
});

it('calls the callback without a transaction under both runners', function (): void {
    $code = <<<'PHP'
        <?php

        use Illuminate\Support\Facades\DB;

        final class Checkout
        {
            public function pay($order)
            {
                return DB::transaction(fn () => $order->pay());
            }
        }
        PHP;
    $changed = [
        "-        return DB::transaction(fn () => \$order->pay());\n+        return (fn () => \$order->pay())();",
    ];
    $mutates = Mutates::with(new UnwrapTransaction(), $code);

    expect(iterator_to_array($mutates->underPest(), preserve_keys: false))->toBe($changed)
        ->and(iterator_to_array($mutates->underInfection(), preserve_keys: false))->toBe($changed);
});

it('leaves alone another method of the facade, a transaction with no callback, and one on a connection', function (): void {
    $code = <<<'PHP'
        <?php

        DB::beginTransaction();
        DB::transaction();
        $connection->transaction(fn () => 1);
        PHP;

    expect(Mutates::with(new UnwrapTransaction(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new UnwrapTransaction()->mutate(new Nop()))->toEqual(Unchanged::node());
});
