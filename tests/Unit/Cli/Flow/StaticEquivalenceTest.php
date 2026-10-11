<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\Equivalence;
use NightWorksIO\MutationGate\Config\Setting;
use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\ProvenMoney;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

afterEach(function (): void {
    Scratch::sweep();
});

it('proves nothing where the mutant differs, the runner cannot give it, the file is gone or the check is off', function (
    Closure $setUp,
): void {
    /** @var array{ScriptedRunner, string, list<Setting>} $given */
    $given = $setUp();
    [$runner, $project, $settings] = $given;

    expect(ProvenMoney::proven($runner, $project, ...$settings))->toBe([]);
})->with([
    'a changed program' => [fn(): array => [
        ScriptedRunner::fixture()->checking(Checkable::inPlace(Contents::of("<?php\n\nfinal class Money\n{\n    const A = 1;\n}\n"))),
        '',
        [],
    ]],
    'no mutant from the runner' => [fn(): array => [
        ScriptedRunner::fixture()->checking(CannotJudge::because('The runner cannot print it.')),
        '',
        [],
    ]],
    'the file gone' => [function (): array {
        $project = Flows::project();
        unlink(sprintf('%s/src/Money.php', $project));

        return [ScriptedRunner::fixture()->checking(Checkable::inPlace(Contents::of(ProvenMoney::EQUIVALENT))), $project, []];
    }],
    'equivalence.static false' => [fn(): array => [
        ScriptedRunner::fixture()->checking(Checkable::inPlace(Contents::of(ProvenMoney::EQUIVALENT))),
        '',
        [Equivalence::notProvenStatically()],
    ]],
]);
