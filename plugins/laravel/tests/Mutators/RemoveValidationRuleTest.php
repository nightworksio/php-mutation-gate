<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateLaravel\Mutators\RemoveValidationRule;
use PhpParser\Node\Stmt\Nop;

it('is named in the laravel set, a collection change, untagged, with its own hint', function (): void {
    $mutator = new RemoveValidationRule();

    expect($mutator->name()->value())->toBe('laravel/RemoveValidationRule')
        ->and($mutator->family())->toBe(MutatorFamily::Collection)
        ->and($mutator->tags())->toEqual(Tags::none())
        ->and($mutator->hint())->toEqual(Hint::that('No test sends input this rule alone refuses.'));
});

it('drops one rule of a field under both runners: an item of a list, and the first of a string', function (): void {
    $code = <<<'PHP'
        <?php

        use Illuminate\Support\Facades\Validator;

        final class SignUp
        {
            public function store($request, $data)
            {
                $request->validate([
                    'email' => ['required', 'email'],
                    'name' => 'required|max:255',
                ]);
                $this->validate($request, ['title' => 'required']);
                Validator::make($data, ['age' => ['integer']]);
            }
        }
        PHP;
    $changed = [
        "-            'email' => ['required', 'email'],\n+            'email' => ['email'],",
        "-            'email' => ['required', 'email'],\n+            'email' => ['required'],",
        "-            'name' => 'required|max:255',\n+            'name' => 'max:255',",
        "-        \$this->validate(\$request, ['title' => 'required']);\n+        \$this->validate(\$request, ['title' => '']);",
        "-        Validator::make(\$data, ['age' => ['integer']]);\n+        Validator::make(\$data, ['age' => []]);",
    ];
    $mutates = Mutates::with(new RemoveValidationRule(), $code);

    expect(iterator_to_array($mutates->underPest(), preserve_keys: false))->toBe($changed)
        ->and(iterator_to_array($mutates->underInfection(), preserve_keys: false))->toBe($changed);
});

it('leaves alone the messages, rules in the wrong place, another call, a list with no field names and a rule that is no string', function (): void {
    $code = <<<'PHP'
        <?php

        $request->validate($rules, ['email.required' => 'An email, please']);
        $this->validate(['title' => 'required']);
        Validator::make(['age' => ['integer']], $rules);
        Validator::other($data, ['age' => 'integer']);
        $request->validate(['required', 'email']);
        $request->other(['email' => 'required']);
        $request->validate(['email' => strtolower('REQUIRED')]);
        PHP;

    expect(Mutates::with(new RemoveValidationRule(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new RemoveValidationRule()->mutate(new Nop()))->toEqual(Unchanged::node());
});
