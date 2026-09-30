<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Order\Enclosing;
use NightWorksIO\MutationGate\Core\Php\Nameless;

it('names the function a mutant is in by its file and its name, and none where it is in no named one', function (): void {
    $in = Enclosing::of(Path::of('src/Money.php'), 'add');

    expect($in)->toEqual(Enclosing::named(Path::of('src/Money.php'), 'add'))
        ->and($in instanceof Enclosing ? [$in->file()->value(), $in->function()] : [])->toBe(['src/Money.php', 'add'])
        ->and(Enclosing::of(Path::of('src/Money.php'), Nameless::code()))->toEqual(Nameless::code());
});
