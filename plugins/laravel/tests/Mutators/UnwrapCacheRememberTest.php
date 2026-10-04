<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateLaravel\Mutators\UnwrapCacheRemember;
use PhpParser\Node\Stmt\Nop;

it('is named in the laravel set, an unwrap, untagged, with its own hint', function (): void {
    $mutator = new UnwrapCacheRemember();

    expect($mutator->name()->value())->toBe('laravel/UnwrapCacheRemember')
        ->and($mutator->family())->toBe(MutatorFamily::Unwrap)
        ->and($mutator->tags())->toEqual(Tags::none())
        ->and($mutator->hint())->toEqual(Hint::that('No test reads the value twice and checks that the second read comes from the cache.'));
});

it('computes the value every time under both runners', function (): void {
    $code = <<<'PHP'
        <?php

        use Illuminate\Support\Facades\Cache;

        final class Stats
        {
            public function all()
            {
                return Cache::remember('stats', 60, fn () => $this->count());
            }
        }
        PHP;
    $changed = [
        "-        return Cache::remember('stats', 60, fn () => \$this->count());\n+        return (fn () => \$this->count())();",
    ];
    $mutates = Mutates::with(new UnwrapCacheRemember(), $code);

    expect(iterator_to_array($mutates->underPest(), preserve_keys: false))->toBe($changed)
        ->and(iterator_to_array($mutates->underInfection(), preserve_keys: false))->toBe($changed);
});

it('leaves alone another method of the facade, a call with no callback, and one on a store', function (): void {
    $code = <<<'PHP'
        <?php

        Cache::get('stats');
        Cache::remember('stats', 60);
        $cache->remember('stats', 60, fn () => 1);
        PHP;

    expect(Mutates::with(new UnwrapCacheRemember(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new UnwrapCacheRemember()->mutate(new Nop()))->toEqual(Unchanged::node());
});
