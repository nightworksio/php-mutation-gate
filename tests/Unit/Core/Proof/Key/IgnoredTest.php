<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Proof\Key\Ignored;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

it('ignores nothing by default', function (): void {
    expect(Ignored::nothing()->matches(Path::of('docs/index.md')))->toBeFalse();
});

it('ignores the paths its globs match, a star within one directory and two stars across them', function (): void {
    $ignored = Ignored::globs('docs/**', '**/*.png', '*.md');

    expect($ignored->matches(Path::of('docs/guide/index.md')))->toBeTrue()
        ->and($ignored->matches(Path::of('resources/images/logo.png')))->toBeTrue()
        ->and($ignored->matches(Path::of('CHANGELOG.md')))->toBeTrue()
        ->and($ignored->matches(Path::of('resources/views/help.md')))->toBeFalse()
        ->and($ignored->matches(Path::of('src/Money.php')))->toBeFalse();
});

it('says which of its globs match a file that defines the runner, which no glob leaves out', function (): void {
    $ignored = Ignored::globs('phpunit.*', 'docs/**', '*.xml', 'config/*.xml');

    expect($ignored->overruledFor(Path::of('phpunit.xml')))->toEqual(Warnings::of(
        Warning::that('proofs.ignore lists phpunit.*, which matches phpunit.xml. That file defines the runner, so every proof key reads it.'),
        Warning::that('proofs.ignore lists *.xml, which matches phpunit.xml. That file defines the runner, so every proof key reads it.'),
    ))
        ->and(Ignored::nothing()->overruledFor(Path::of('phpunit.xml')))->toEqual(Warnings::none());
});
