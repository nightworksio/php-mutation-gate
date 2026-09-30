<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\References;
use NightWorksIO\MutationGate\Core\Php\Site;
use NightWorksIO\MutationGate\Tests\Support\Php;

it('gathers sites, and stays ambiguous once any part is', function (): void {
    $source = Php::source("<?php\nA;\nB;");
    $a = References::at(Site::in($source, 0));
    $b = References::at(Site::in($source, 2));

    expect(Php::sites($a->and($b)))->toBe(['src/A.php:2', 'src/A.php:3'])
        ->and($a->and($b)->isAmbiguous())->toBeFalse()
        ->and($a->and(References::unknown())->isAmbiguous())->toBeTrue()
        ->and(References::unknown()->and($a)->isAmbiguous())->toBeTrue()
        ->and(References::none()->sites())->toBe([])
        ->and(References::none()->isAmbiguous())->toBeFalse()
        ->and($a->and(References::unknown())->withoutSites())->toEqual(References::unknown())
        ->and($a->withoutSites())->toEqual(References::none());
});
