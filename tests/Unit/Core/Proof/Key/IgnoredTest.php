<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Proof\Key\Ignored;

it('ignores nothing by default', function (): void {
    expect(Ignored::nothing()->matches(Path::of('docs/index.md')))->toBeFalse();
});

it('ignores the paths its globs match, a star matching across directories', function (): void {
    $ignored = Ignored::globs('docs/**', '*.png');

    expect($ignored->matches(Path::of('docs/guide/index.md')))->toBeTrue()
        ->and($ignored->matches(Path::of('resources/images/logo.png')))->toBeTrue()
        ->and($ignored->matches(Path::of('src/Money.php')))->toBeFalse();
});
