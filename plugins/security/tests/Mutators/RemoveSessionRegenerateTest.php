<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateSecurity\Mutators\RemoveSessionRegenerate;
use PhpParser\Node\Stmt\Nop;

it('is named in the security set, a removed call, about security, with its own hint', function (): void {
    $mutator = new RemoveSessionRegenerate();

    expect($mutator->name()->value())->toBe('security/RemoveSessionRegenerate')
        ->and($mutator->family())->toBe(MutatorFamily::RemovedCall)
        ->and($mutator->tags())->toEqual(Tags::of(Tag::security()))
        ->and($mutator->hint())->toEqual(Hint::that('No test checks that the session id changes when the user signs in.'));
});

it('removes the new session id under both runners, emptying it under Infection', function (): void {
    $code = <<<'PHP'
        <?php

        final class Login
        {
            public function signIn($user)
            {
                session_regenerate_id(true);
                $_SESSION['user'] = $user->id;
            }
        }
        PHP;
    $mutates = Mutates::with(new RemoveSessionRegenerate(), $code);

    expect(iterator_to_array($mutates->underPest(), preserve_keys: false))->toBe([
        "-        session_regenerate_id(true);",
    ])
        ->and(iterator_to_array($mutates->underInfection(), preserve_keys: false))->toBe([
            "-        session_regenerate_id(true);\n+        ",
        ]);
});

it('leaves alone a call whose result is used and another session function', function (): void {
    $code = <<<'PHP'
        <?php

        $regenerated = session_regenerate_id();
        session_start();
        PHP;

    expect(Mutates::with(new RemoveSessionRegenerate(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new RemoveSessionRegenerate()->mutate(new Nop()))->toEqual(Unchanged::node());
});
