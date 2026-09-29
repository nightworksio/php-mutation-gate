<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Globs;
use NightWorksIO\MutationGate\Core\File\Path;

it('matches a path any one of them matches', function (): void {
    $globs = Globs::of(Glob::of('config/**'), Glob::of('routes/*.php'));

    expect($globs->matches(Path::of('config/app.php')))->toBeTrue()
        ->and($globs->matches(Path::of('routes/web.php')))->toBeTrue()
        ->and($globs->matches(Path::of('app/Kernel.php')))->toBeFalse();
});

it('matches nothing when there are none', function (): void {
    expect(Globs::of()->matches(Path::of('config/app.php')))->toBeFalse();
});

it('matches what one added to them matches, and leaves the ones it came from as they were', function (): void {
    $globs = Globs::of(Glob::of('config/**'));
    $more = $globs->with(Glob::of('routes/*.php'));

    expect($more->matches(Path::of('routes/web.php')))->toBeTrue()
        ->and($more->matches(Path::of('config/app.php')))->toBeTrue()
        ->and($globs->matches(Path::of('routes/web.php')))->toBeFalse();
});
