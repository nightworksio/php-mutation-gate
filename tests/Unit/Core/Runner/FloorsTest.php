<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Runner\Floors;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Runner\Versions;

$floors = static fn(): Floors => Floors::of(BuiltinRunner::Codeception, '5.3.0');
$versions = static fn(string $framework, string $coverage): Versions => Versions::of(
    Version::of('codeception/codeception', $framework, 'a'),
    Version::of('phpunit/php-code-coverage', $coverage, 'b'),
);

it('drives the runner\'s framework and php-code-coverage', function () use ($floors): void {
    expect($floors()->driven())->toBe(['codeception/codeception', 'phpunit/php-code-coverage']);
});

it('holds the versions at the framework\'s floor and php-code-coverage 14 or later, and any that is no release', function (string $framework, string $coverage) use ($floors, $versions): void {
    expect($floors()->held($versions($framework, $coverage), '/v/installed.json'))->toEqual($versions($framework, $coverage));
})->with([['5.3.0', '14.0.0'], ['v5.4.1', '14.4.1'], ['dev-main', '14.4.1'], ['5.3.7', '13.0.x-dev']]);

it('refuses a framework or a php-code-coverage below its floor, naming the runner, the package and the manifest', function (string $framework, string $coverage, string $said) use ($floors, $versions): void {
    expect($floors()->held($versions($framework, $coverage), '/v/installed.json'))->toEqual(CannotJudge::because($said));
})->with([
    'the framework' => ['5.2.9', '14.4.1', 'The codeception runner needs codeception/codeception 5.3.0 or later, and /v/installed.json holds 5.2.9.'],
    'php-code-coverage' => ['5.3.7', '13.9.0', 'The codeception runner needs phpunit/php-code-coverage 14.0.0 or later, and /v/installed.json holds 13.9.0.'],
]);
