<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateSecurity\Mutators\FilterVarToInput;
use PhpParser\Node\Stmt\Nop;

it('is named in the security set, an unwrap, about security, with its own hint', function (): void {
    $mutator = new FilterVarToInput();

    expect($mutator->name()->value())->toBe('security/FilterVarToInput')
        ->and($mutator->family())->toBe(MutatorFamily::Unwrap)
        ->and($mutator->tags())->toEqual(Tags::of(Tag::security()))
        ->and($mutator->hint())->toEqual(Hint::that('No test passes invalid input and checks that it is refused.'));
});

it('takes any input as valid under both runners', function (): void {
    $code = <<<'PHP'
        <?php

        final class Input
        {
            public function email($email)
            {
                return filter_var($email, FILTER_VALIDATE_EMAIL);
            }

            public function port($port)
            {
                return filter_var($port, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            }
        }
        PHP;
    $changed = [
        "-        return filter_var(\$email, FILTER_VALIDATE_EMAIL);\n+        return \$email;",
        "-        return filter_var(\$port, \\FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);\n+        return \$port;",
    ];
    $mutates = Mutates::with(new FilterVarToInput(), $code);

    expect(iterator_to_array($mutates->underPest(), preserve_keys: false))->toBe($changed)
        ->and(iterator_to_array($mutates->underInfection(), preserve_keys: false))->toBe($changed);
});

it('leaves alone a filter that sanitises, a call with no filter, a filter held in a variable and another function', function (): void {
    $code = <<<'PHP'
        <?php

        filter_var($text, FILTER_SANITIZE_SPECIAL_CHARS);
        filter_var($text);
        filter_var($text, $filter);
        filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        PHP;

    expect(Mutates::with(new FilterVarToInput(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new FilterVarToInput()->mutate(new Nop()))->toEqual(Unchanged::node());
});
