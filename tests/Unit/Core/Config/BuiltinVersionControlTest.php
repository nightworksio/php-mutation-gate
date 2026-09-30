<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\BuiltinVersionControl;
use NightWorksIO\MutationGate\Core\Config\Name;

it('names each built-in version control by the name the registry holds it by', function (
    BuiltinVersionControl $builtin,
): void {
    expect($builtin->named())->toEqual(Name::of($builtin->value));
})->with(BuiltinVersionControl::cases());

it('takes GitHub\'s word under GitHub Actions, and git\'s alone anywhere else', function (
    Variables $variables,
    BuiltinVersionControl $builtin,
): void {
    expect(BuiltinVersionControl::in($variables))->toBe($builtin);
})->with([
    'GitHub Actions' => [Variables::of(['GITHUB_ACTIONS' => 'true']), BuiltinVersionControl::GitHub],
    'GitLab CI' => [Variables::of(['GITLAB_CI' => 'true']), BuiltinVersionControl::Git],
    'no CI' => [Variables::of([]), BuiltinVersionControl::Git],
]);
