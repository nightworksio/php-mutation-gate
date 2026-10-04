<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateLaravel\Mutators\RemoveGuardedEntry;
use PhpParser\Node\Stmt\Nop;

it('is named in the laravel set, a collection change, about security, with its own hint', function (): void {
    $mutator = new RemoveGuardedEntry();

    expect($mutator->name()->value())->toBe('laravel/RemoveGuardedEntry')
        ->and($mutator->family())->toBe(MutatorFamily::Collection)
        ->and($mutator->tags())->toEqual(Tags::of(Tag::security()))
        ->and($mutator->hint())->toEqual(Hint::that('No test posts this guarded field and checks that it is not saved.'));
});

it('unguards each guarded field under Pest, and nothing under Infection, which never offers a property', function (): void {
    $code = <<<'PHP'
        <?php

        final class User extends Model
        {
            protected $guarded = ['id', 'is_admin'];
        }
        PHP;
    $mutates = Mutates::with(new RemoveGuardedEntry(), $code);

    expect(iterator_to_array($mutates->underPest(), preserve_keys: false))->toBe([
        "-    protected \$guarded = ['id', 'is_admin'];\n+    protected \$guarded = ['is_admin'];",
        "-    protected \$guarded = ['id', 'is_admin'];\n+    protected \$guarded = ['id'];",
    ])
        ->and(iterator_to_array($mutates->underInfection(), preserve_keys: false))->toBe([]);
});

it('leaves alone the fillable fields, another list and a list a method returns', function (): void {
    $code = <<<'PHP'
        <?php

        final class User extends Model
        {
            protected $fillable = ['name', 'email'];

            protected $casts = ['guarded' => ['id']];

            public function guarded()
            {
                return ['id'];
            }
        }
        PHP;

    expect(Mutates::with(new RemoveGuardedEntry(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new RemoveGuardedEntry()->mutate(new Nop()))->toEqual(Unchanged::node());
});
