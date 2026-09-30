<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Mago\Binary;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Tests\Support\FakeAnalyser;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('finds the binary the package downloaded for the version Composer installed', function (): void {
    $project = FakeAnalyser::mago('1.50.0', '');

    expect(Binary::in(sprintf('%s/vendor', $project)))->toBe(sprintf('%s/mago', FakeAnalyser::scripts($project)));
});

it('cannot judge where Composer installed no Mago, or lists nothing it can read', function (): void {
    $none = Scratch::directory();
    $unreadable = Scratch::directory();
    Scratch::write($unreadable, 'vendor/composer/installed.json', '[]');

    expect(Binary::in(sprintf('%s/vendor', $none)))
        ->toEqual(CannotJudge::because(sprintf('Composer lists no carthage-software/mago in %s/vendor/composer/installed.json: install it, with composer require --dev carthage-software/mago.', $none)))
        ->and(Binary::in(sprintf('%s/vendor', $unreadable)))->toBeInstanceOf(CannotJudge::class);
});
