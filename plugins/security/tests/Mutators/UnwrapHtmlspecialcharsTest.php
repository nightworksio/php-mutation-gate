<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateSecurity\Mutators\UnwrapHtmlspecialchars;
use NightWorksIO\MutationGateSecurity\OtherUnwraps;
use PhpParser\Node\Stmt\Nop;

it('is named in the security set, an unwrap, about security, with its own hint', function (): void {
    $mutator = new UnwrapHtmlspecialchars();

    expect($mutator->name()->value())->toBe('security/UnwrapHtmlspecialchars')
        ->and($mutator->family())->toBe(MutatorFamily::Unwrap)
        ->and($mutator->tags())->toEqual(Tags::of(Tag::security()))
        ->and($mutator->hint())->toEqual(Hint::that('No test passes markup and checks that `htmlspecialchars()` escapes it.'));
});

it('names the default set\'s and Pest\'s own unwraps of its function', function (): void {
    expect(new UnwrapHtmlspecialchars()->madeAlsoBy())->toEqual(OtherUnwraps::named('UnwrapHtmlspecialchars'));
});

it('leaves markup as it came under both runners, in any case', function (): void {
    $code = <<<'PHP'
        <?php

        final class Page
        {
            public function paragraph($text)
            {
                return '<p>' . htmlspecialchars($text, ENT_QUOTES) . '</p>';
            }

            public function heading($title)
            {
                $title = HTMLSPECIALCHARS($title);

                return $title;
            }
        }
        PHP;
    $changed = [
        "-        return '<p>' . htmlspecialchars(\$text, ENT_QUOTES) . '</p>';\n+        return '<p>' . \$text . '</p>';",
        "-        \$title = HTMLSPECIALCHARS(\$title);\n+        \$title = \$title;",
    ];
    $mutates = Mutates::with(new UnwrapHtmlspecialchars(), $code);

    expect(iterator_to_array($mutates->underPest(), preserve_keys: false))->toBe($changed)
        ->and(iterator_to_array($mutates->underInfection(), preserve_keys: false))->toBe($changed);
});

it('leaves alone another function, a call with no argument and a first-class callable', function (): void {
    $code = <<<'PHP'
        <?php

        htmlspecialchars_decode($text);
        htmlspecialchars();
        $escape = htmlspecialchars(...);
        PHP;

    expect(Mutates::with(new UnwrapHtmlspecialchars(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new UnwrapHtmlspecialchars()->mutate(new Nop()))->toEqual(Unchanged::node());
});
