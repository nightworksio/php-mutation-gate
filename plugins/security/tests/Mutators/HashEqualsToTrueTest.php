<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateSecurity\Mutators\HashEqualsToTrue;
use PhpParser\Node\Stmt\Nop;

it('is named in the security set, a condition, about security, with its own hint', function (): void {
    $mutator = new HashEqualsToTrue();

    expect($mutator->name()->value())->toBe('security/HashEqualsToTrue')
        ->and($mutator->family())->toBe(MutatorFamily::Condition)
        ->and($mutator->tags())->toEqual(Tags::of(Tag::security()))
        ->and($mutator->hint())->toEqual(Hint::that('No test passes a wrong token and checks that it is refused.'));
});

it('accepts every token under both runners', function (): void {
    $code = <<<'PHP'
        <?php

        namespace App;

        final class Webhook
        {
            public function accepts($given)
            {
                return hash_equals($this->token, $given);
            }
        }
        PHP;
    $changed = [
        "-        return hash_equals(\$this->token, \$given);\n+        return true;",
    ];
    $mutates = Mutates::with(new HashEqualsToTrue(), $code);

    expect(iterator_to_array($mutates->underPest(), preserve_keys: false))->toBe($changed)
        ->and(iterator_to_array($mutates->underInfection(), preserve_keys: false))->toBe($changed);
});

it('leaves alone another function, one of another namespace and a method', function (): void {
    $code = <<<'PHP'
        <?php

        hash_hmac('sha256', $body, $key);
        Acme\hash_equals($a, $b);
        $comparer->hash_equals($a, $b);
        PHP;

    expect(Mutates::with(new HashEqualsToTrue(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new HashEqualsToTrue()->mutate(new Nop()))->toEqual(Unchanged::node());
});
