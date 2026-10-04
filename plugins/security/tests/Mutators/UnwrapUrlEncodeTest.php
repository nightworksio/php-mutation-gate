<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateSecurity\Mutators\UnwrapUrlEncode;
use PhpParser\Node\Stmt\Nop;

it('is named in the security set, an unwrap, about security, with its own hint', function (): void {
    $mutator = new UnwrapUrlEncode();

    expect($mutator->name()->value())->toBe('security/UnwrapUrlEncode')
        ->and($mutator->family())->toBe(MutatorFamily::Unwrap)
        ->and($mutator->tags())->toEqual(Tags::of(Tag::security()))
        ->and($mutator->hint())->toEqual(Hint::that('No test passes a reserved character and checks that it comes out encoded.'));
});

it('leaves the value unencoded under both runners', function (): void {
    $code = <<<'PHP'
        <?php

        final class Links
        {
            public function search($query)
            {
                return '/search?q=' . urlencode($query);
            }

            public function file($name)
            {
                return '/files/' . rawurlencode($name);
            }
        }
        PHP;
    $changed = [
        "-        return '/search?q=' . urlencode(\$query);\n+        return '/search?q=' . \$query;",
        "-        return '/files/' . rawurlencode(\$name);\n+        return '/files/' . \$name;",
    ];
    $mutates = Mutates::with(new UnwrapUrlEncode(), $code);

    expect(iterator_to_array($mutates->underPest(), preserve_keys: false))->toBe($changed)
        ->and(iterator_to_array($mutates->underInfection(), preserve_keys: false))->toBe($changed);
});

it('leaves alone decoding and building a query', function (): void {
    $code = <<<'PHP'
        <?php

        urldecode($query);
        http_build_query($query);
        PHP;

    expect(Mutates::with(new UnwrapUrlEncode(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new UnwrapUrlEncode()->mutate(new Nop()))->toEqual(Unchanged::node());
});
