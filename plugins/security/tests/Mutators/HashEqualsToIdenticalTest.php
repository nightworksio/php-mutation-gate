<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\SourcePin;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateSecurity\Mutators\HashEqualsToIdentical;
use PhpParser\Node\Stmt\Nop;

it('is named in the security set, a condition, about security, with its own hint, pinned by its call to hash_equals', function (): void {
    $mutator = new HashEqualsToIdentical();

    expect($mutator->name()->value())->toBe('security/HashEqualsToIdentical')
        ->and($mutator->family())->toBe(MutatorFamily::Condition)
        ->and($mutator->tags())->toEqual(Tags::of(Tag::security()))
        ->and($mutator->hint())->toEqual(Hint::that(
            'Only a test that reads the source can pin a constant-time comparison: one that runs it, then asserts that its file still calls `hash_equals`.',
        ))
        ->and($mutator->pin())->toEqual(SourcePin::call('hash_equals'))
        ->and($mutator->pin()->text())->toBe('hash_equals(');
});

it('compares in variable time under both runners', function (): void {
    $code = <<<'PHP'
        <?php

        final class Webhook
        {
            public function accepts($given)
            {
                return \hash_equals($this->token, $given);
            }
        }
        PHP;
    $changed = [
        "-        return \\hash_equals(\$this->token, \$given);\n+        return \$this->token === \$given;",
    ];
    $mutates = Mutates::with(new HashEqualsToIdentical(), $code);

    expect(iterator_to_array($mutates->underPest(), preserve_keys: false))->toBe($changed)
        ->and(iterator_to_array($mutates->underInfection(), preserve_keys: false))->toBe($changed);
});

it('leaves alone a call with one argument, one that spreads its arguments, and a first-class callable', function (): void {
    $code = <<<'PHP'
        <?php

        hash_equals($token);
        hash_equals(...$pair);
        hash_equals(...);
        PHP;

    expect(Mutates::with(new HashEqualsToIdentical(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new HashEqualsToIdentical()->mutate(new Nop()))->toEqual(Unchanged::node());
});
