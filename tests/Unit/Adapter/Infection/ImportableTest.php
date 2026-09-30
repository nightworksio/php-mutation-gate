<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Importable;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Doctor\InfectionConfig;

it('reads whether an Infection config sets its own floor, and whether it ignores mutants itself', function (string $text, bool $minMsi, bool $ignores): void {
    expect(Importable::in('infection.json5', $text))->toEqual(InfectionConfig::in('infection.json5', minMsi: $minMsi, ignores: $ignores));
})->with([
    'a minMsi' => ['{minMsi: 80}', true, false],
    'a minCoveredMsi, with a comment' => ["{\n  // the covered floor\n  minCoveredMsi: 90,\n}", true, false],
    'a mutator\'s ignore' => ['{"mutators": {"Plus": {"ignore": ["App\\\\Money::add"]}}}', false, true],
    'a global ignore' => ['{"mutators": {"global-ignore": ["App\\\\Legacy"]}}', false, true],
    'neither' => ['{"source": {"directories": ["src"]}, "mutators": {"@default": true}}', false, false],
]);

it('cannot judge text that is not a config Infection could read', function (): void {
    expect(Importable::in('infection.json5', '{minMsi: '))->toBeInstanceOf(CannotJudge::class);
});
