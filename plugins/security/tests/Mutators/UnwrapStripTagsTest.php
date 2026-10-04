<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateSecurity\Mutators\UnwrapStripTags;
use NightWorksIO\MutationGateSecurity\OtherUnwraps;
use PhpParser\Node\Stmt\Nop;

it('is named in the security set, an unwrap, about security, with its own hint', function (): void {
    $mutator = new UnwrapStripTags();

    expect($mutator->name()->value())->toBe('security/UnwrapStripTags')
        ->and($mutator->family())->toBe(MutatorFamily::Unwrap)
        ->and($mutator->tags())->toEqual(Tags::of(Tag::security()))
        ->and($mutator->hint())->toEqual(Hint::that('No test passes markup and checks that `strip_tags()` removes it.'));
});

it('names the default set\'s and Pest\'s own unwraps of its function', function (): void {
    expect(new UnwrapStripTags()->madeAlsoBy())->toEqual(OtherUnwraps::named('UnwrapStripTags'));
});

it('leaves markup as it came under both runners, in any case', function (): void {
    $code = <<<'PHP'
        <?php

        final class Page
        {
            public function paragraph($text)
            {
                return '<p>' . strip_tags($text, '<b>') . '</p>';
            }

            public function heading($title)
            {
                $title = STRIP_TAGS($title);

                return $title;
            }
        }
        PHP;
    $changed = [
        "-        return '<p>' . strip_tags(\$text, '<b>') . '</p>';\n+        return '<p>' . \$text . '</p>';",
        "-        \$title = STRIP_TAGS(\$title);\n+        \$title = \$title;",
    ];
    $mutates = Mutates::with(new UnwrapStripTags(), $code);

    expect(iterator_to_array($mutates->underPest(), preserve_keys: false))->toBe($changed)
        ->and(iterator_to_array($mutates->underInfection(), preserve_keys: false))->toBe($changed);
});

it('leaves alone another function, a call with no argument and a first-class callable', function (): void {
    $code = <<<'PHP'
        <?php

        htmlspecialchars($text);
        strip_tags();
        $escape = strip_tags(...);
        PHP;

    expect(Mutates::with(new UnwrapStripTags(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new UnwrapStripTags()->mutate(new Nop()))->toEqual(Unchanged::node());
});
