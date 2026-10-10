<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\BuiltinVersionControl;
use NightWorksIO\MutationGate\Core\Config\Name;

it('names each built-in version control by the name the registry holds it by', function (): void {
    foreach (BuiltinVersionControl::cases() as $builtin) {
        expect($builtin->named())->toEqual(Name::of($builtin->value));
    }
});

it('takes GitHub\'s word under GitHub Actions, and git\'s alone anywhere else', function (
    Variables $variables,
    BuiltinVersionControl $builtin,
): void {
    expect(BuiltinVersionControl::in($variables))->toBe($builtin);
})->with([
    'GitHub Actions' => [fn(): Variables => Variables::of(['GITHUB_ACTIONS' => 'true']), BuiltinVersionControl::GitHub],
    'GitLab CI' => [fn(): Variables => Variables::of(['GITLAB_CI' => 'true']), BuiltinVersionControl::Git],
    'no CI' => [fn(): Variables => Variables::of([]), BuiltinVersionControl::Git],
]);
