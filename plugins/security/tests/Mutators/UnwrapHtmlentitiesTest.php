<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateSecurity\Mutators\UnwrapHtmlentities;
use NightWorksIO\MutationGateSecurity\OtherUnwraps;
use PhpParser\Node\Stmt\Nop;

it('is named in the security set, an unwrap, about security, with its own hint', function (): void {
    $mutator = new UnwrapHtmlentities();

    expect($mutator->name()->value())->toBe('security/UnwrapHtmlentities')
        ->and($mutator->family())->toBe(MutatorFamily::Unwrap)
        ->and($mutator->tags())->toEqual(Tags::of(Tag::security()))
        ->and($mutator->hint())->toEqual(Hint::that('No test passes markup and checks that `htmlentities()` escapes it.'));
});

it('names the default set\'s and Pest\'s own unwraps of its function', function (): void {
    expect(new UnwrapHtmlentities()->madeAlsoBy())->toEqual(OtherUnwraps::named('UnwrapHtmlentities'));
});

it('leaves markup as it came under both runners, in any case', function (): void {
    $code = <<<'PHP'
        <?php

        final class Page
        {
            public function paragraph($text)
            {
                return '<p>' . htmlentities($text, ENT_QUOTES) . '</p>';
            }

            public function heading($title)
            {
                $title = HTMLENTITIES($title);

                return $title;
            }
        }
        PHP;
    $changed = [
        "-        return '<p>' . htmlentities(\$text, ENT_QUOTES) . '</p>';\n+        return '<p>' . \$text . '</p>';",
        "-        \$title = HTMLENTITIES(\$title);\n+        \$title = \$title;",
    ];
    $mutates = Mutates::with(new UnwrapHtmlentities(), $code);

    expect(iterator_to_array($mutates->underPest(), preserve_keys: false))->toBe($changed)
        ->and(iterator_to_array($mutates->underInfection(), preserve_keys: false))->toBe($changed);
});

it('leaves alone another function, a call with no argument and a first-class callable', function (): void {
    $code = <<<'PHP'
        <?php

        html_entity_decode($text);
        htmlentities();
        $escape = htmlentities(...);
        PHP;

    expect(Mutates::with(new UnwrapHtmlentities(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new UnwrapHtmlentities()->mutate(new Nop()))->toEqual(Unchanged::node());
});
