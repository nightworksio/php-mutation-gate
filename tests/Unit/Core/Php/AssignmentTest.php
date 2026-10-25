<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\Assignment;
use NightWorksIO\MutationGate\Core\Php\OwnVariables;

it('keeps to its scope a variable assigned what runs nothing, and none assigned what begins or ends with a variable', function (string $statement, bool $kept): void {
    $tokens = array_values(array_filter(
        PhpToken::tokenize(sprintf('<?php %s', $statement)),
        static fn(PhpToken $token): bool => ! $token->isIgnorable() && ! $token->is(T_OPEN_TAG),
    ));

    expect($tokens !== [] && Assignment::keepsToItsScope($tokens, OwnVariables::none()))->toBe($kept);
})->with([
    'what runs nothing' => ['$total = 1 + 2;', true],
    'what begins with a variable' => ['$total = $amount + 1;', false],
    'what ends with a variable' => ['$total = 1 + $amount;', false],
]);
