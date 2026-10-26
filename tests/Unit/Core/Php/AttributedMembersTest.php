<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\AttributedMember;
use NightWorksIO\MutationGate\Core\Php\AttributedMembers;
use NightWorksIO\MutationGate\Core\Php\Tokens;
use NightWorksIO\MutationGate\Core\Php\TopLevel;

it('names no holder for a run that stands on a closure, an anonymous class\'s method or what is no class or function', function (): void {
    $code = <<<'CODE'
        <?php

        $kept = #[A] fn () => 1;
        $made = #[B] function () {};
        new class {
            #[C]
            public function run(): void {}

            #[D]
            public int $count = 0;
        };
        CODE;
    $tokens = array_values(array_filter(PhpToken::tokenize($code), static fn(PhpToken $token): bool => ! $token->isIgnorable()));

    expect(array_map(
        static fn(AttributedMember $member): array => [$member->standing()->value, $member->holder()],
        AttributedMembers::in(Tokens::of($tokens), TopLevel::of($tokens)->scope()),
    ))->toBe([['kept closure', ''], ['kept closure', ''], ['elsewhere', ''], ['elsewhere', '']]);
});
