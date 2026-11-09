<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\BuiltinReporter;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Outputs;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Cost\RunAccount;
use NightWorksIO\MutationGate\Core\Cost\Untimed;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('writes each automatic reporter where no layer lists them, and only those a layer lists, and none of a verdict alone', function (): void {
    expect(BuiltinReporter::GitHubComment->isAutomaticUnder(Outputs::of()))->toBeTrue()
        ->and(BuiltinReporter::Badge->isAutomaticUnder(Outputs::standard()))->toBeTrue()
        ->and(BuiltinReporter::Json->isAutomaticUnder(Outputs::standard()))->toBeFalse()
        ->and(BuiltinReporter::Badge->isAutomaticUnder(Outputs::of(automatic: Listed::of(BuiltinReporter::Badge->value))))->toBeTrue()
        ->and(BuiltinReporter::GitHubSummary->isAutomaticUnder(Outputs::of(automatic: Listed::of(BuiltinReporter::Badge->value))))->toBeFalse()
        ->and(BuiltinReporter::Badge->isAutomaticUnder(Outputs::of(verdictOnly: true)))->toBeFalse()
        ->and(BuiltinReporter::Badge->isAutomaticUnder(Outputs::of(verdictOnly: false)))->toBeTrue();
});

it('says what a run cost, unless outputs.costs is false', function (): void {
    $account = Verdicts::account();
    $uncosted = Outputs::of(costs: false)->said($account);

    expect(Outputs::of()->said($account))->toBe($account)
        ->and(Outputs::of(costs: true)->said($account))->toBe($account)
        ->and($uncosted)->toBeInstanceOf(RunAccount::class)
        ->and($uncosted->timings())->toBeInstanceOf(Untimed::class)
        ->and($uncosted->cost())->toBeInstanceOf(Untimed::class);
});

it('lays a later layer\'s outputs over an earlier one, key by key, and reads them back', function (): void {
    $laid = Configs::valid(['outputs' => ['verdictOnly' => true, 'automatic' => ['badge'], 'costs' => false]])
        ->over(Configs::valid(['outputs' => ['verdictOnly' => false, 'actionReports' => ['json']]]));

    expect(Configs::decoded($laid))->toMatchArray(['outputs' => [
        'verdictOnly' => false,
        'automatic' => ['badge'],
        'actionReports' => ['json'],
        'costs' => false,
    ]]);
});

it('writes each key into a config written as PHP', function (array $outputs, string $call): void {
    expect(Configs::valid(['runner' => 'pest', 'outputs' => $outputs])->php(ProjectRoot::origin())->code())->toContain($call);
})->with([
    'a verdict alone' => [['verdictOnly' => true], 'Outputs::verdictOnly()'],
    'every output' => [['verdictOnly' => false], 'Outputs::everyOutput()'],
    'the automatic' => [['automatic' => ['badge', 'github-summary']], "Outputs::automatic('badge', 'github-summary')"],
    'the action\'s' => [['actionReports' => ['json']], "Outputs::actionReports('json')"],
    'costs' => [['costs' => true], 'Outputs::withCosts()'],
    'no costs' => [['costs' => false], 'Outputs::withoutCosts()'],
]);

it('refuses a reporter that is not one it can leave out', function (): void {
    expect(Configs::problems(Configs::validated(['runner' => 'pest', 'outputs' => ['automatic' => ['json']]])))
        ->toHaveCount(1);
});
