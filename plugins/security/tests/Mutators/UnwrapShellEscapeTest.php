<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateSecurity\Mutators\UnwrapShellEscape;
use PhpParser\Node\Stmt\Nop;

it('is named in the security set, an unwrap, about security, with its own hint', function (): void {
    $mutator = new UnwrapShellEscape();

    expect($mutator->name()->value())->toBe('security/UnwrapShellEscape')
        ->and($mutator->family())->toBe(MutatorFamily::Unwrap)
        ->and($mutator->tags())->toEqual(Tags::of(Tag::security()))
        ->and($mutator->hint())->toEqual(Hint::that('No test passes a shell metacharacter and checks that it comes out escaped.'));
});

it('passes the input to the shell unescaped under both runners', function (): void {
    $code = <<<'PHP'
        <?php

        final class Images
        {
            public function convert($file)
            {
                return exec('convert ' . escapeshellarg($file));
            }

            public function run($command)
            {
                return exec(escapeshellcmd($command));
            }
        }
        PHP;
    $changed = [
        "-        return exec('convert ' . escapeshellarg(\$file));\n+        return exec('convert ' . \$file);",
        "-        return exec(escapeshellcmd(\$command));\n+        return exec(\$command);",
    ];
    $mutates = Mutates::with(new UnwrapShellEscape(), $code);

    expect(iterator_to_array($mutates->underPest(), preserve_keys: false))->toBe($changed)
        ->and(iterator_to_array($mutates->underInfection(), preserve_keys: false))->toBe($changed);
});

it('leaves alone the shell call itself and another escape', function (): void {
    $code = <<<'PHP'
        <?php

        exec($command);
        addslashes($file);
        PHP;

    expect(Mutates::with(new UnwrapShellEscape(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new UnwrapShellEscape()->mutate(new Nop()))->toEqual(Unchanged::node());
});
