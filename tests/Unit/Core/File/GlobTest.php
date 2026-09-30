<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Path;

it('matches a path spelt exactly, and nothing longer or shorter', function (): void {
    expect(Glob::of('phpunit.xml')->matches(Path::of('phpunit.xml')))->toBeTrue()
        ->and(Glob::of('phpunit.xml')->matches(Path::of('phpunit.xml.dist')))->toBeFalse()
        ->and(Glob::of('phpunit.xml')->matches(Path::of('app/phpunit.xml')))->toBeFalse();
});

it('reads a dot as a dot', function (): void {
    expect(Glob::of('phpunit.xml')->matches(Path::of('phpunitaxml')))->toBeFalse();
});

it('matches any run of characters within one segment with a star', function (): void {
    expect(Glob::of('config/*.php')->matches(Path::of('config/app.php')))->toBeTrue()
        ->and(Glob::of('config/*.php')->matches(Path::of('config/.php')))->toBeTrue()
        ->and(Glob::of('config/*.php')->matches(Path::of('config/cache/app.php')))->toBeFalse();
});

it('matches one character of a segment with a question mark', function (): void {
    expect(Glob::of('?.php')->matches(Path::of('a.php')))->toBeTrue()
        ->and(Glob::of('?.php')->matches(Path::of('ab.php')))->toBeFalse()
        ->and(Glob::of('a?b')->matches(Path::of('a/b')))->toBeFalse();
});

it('matches any number of segments with a double star', function (): void {
    expect(Glob::of('bootstrap/**')->matches(Path::of('bootstrap/app.php')))->toBeTrue()
        ->and(Glob::of('bootstrap/**')->matches(Path::of('bootstrap/cache/packages.php')))->toBeTrue()
        ->and(Glob::of('bootstrap/**')->matches(Path::of('routes/web.php')))->toBeFalse();
});

it('matches no segment or any number of them with a double star and a slash', function (): void {
    expect(Glob::of('**/*.php')->matches(Path::of('Kernel.php')))->toBeTrue()
        ->and(Glob::of('**/*.php')->matches(Path::of('app/Http/Kernel.php')))->toBeTrue()
        ->and(Glob::of('**/*.php')->matches(Path::of('app/Http/Kernel.phpx')))->toBeFalse();
});

it('reads every other character as itself, the pattern delimiter among them', function (): void {
    expect(Glob::of('docs/#notes+(draft).md')->matches(Path::of('docs/#notes+(draft).md')))->toBeTrue()
        ->and(Glob::of('docs/#notes+(draft).md')->matches(Path::of('docs/#notess(draft).md')))->toBeFalse();
});

it('knows the directory every path it matches is inside, and how deep such a path can go', function (): void {
    expect(Glob::of('packages/*')->base())->toEqual(Path::of('packages'))
        ->and(Glob::of('packages/*')->depth())->toBe(2)
        ->and(Glob::of('src/Modules/*/Package?')->base())->toEqual(Path::of('src/Modules'))
        ->and(Glob::of('libs/money')->base())->toEqual(Path::of('libs/money'))
        ->and(Glob::of('libs/money')->depth())->toBe(2)
        ->and(Glob::of('*/packages')->base())->toEqual(Path::root())
        ->and(Glob::of('modules/**/composer.json')->base())->toEqual(Path::of('modules'))
        ->and(Glob::of('modules/**/composer.json')->depth())->toBe(PHP_INT_MAX);
});
