<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpSpec\Covering;
use NightWorksIO\MutationGate\Adapter\PhpSpec\Extension;
use NightWorksIO\MutationGate\Adapter\PhpSpec\LaunchOption;
use NightWorksIO\MutationGate\Adapter\PhpSpec\Listing;
use NightWorksIO\MutationGate\Adapter\PhpSpec\OwnFiles;
use NightWorksIO\MutationGate\Adapter\PhpSpec\Recording;
use NightWorksIO\MutationGate\Adapter\PhpSpec\Selecting;
use NightWorksIO\MutationGate\Adapter\PhpSpec\Told;
use NightWorksIO\MutationGate\Core\Native\Variable;
use NightWorksIO\MutationGate\Core\Runner\Opcache;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use PhpSpec\ServiceContainer\IndexedServiceContainer;

afterEach(function (): void {
    putenv(Variable::Results->value);
    putenv(Variable::Guard->value);
    Scratch::sweep();
});

/**
 * The classes of the services PhpSpec's container holds under a tag, once the
 * extension loaded into it was told these arguments.
 *
 * @param list<string> $arguments
 *
 * @return list<string>
 */
function loadedUnder(string $tag, array $arguments): array
{
    $container = new IndexedServiceContainer();
    new Extension(Told::from($arguments), OwnFiles::under('/p', '/p/vendor'))->load($container, []);
    $classes = [];

    foreach ($container->getByTag($tag) as $service) {
        $classes[] = get_debug_type($service);
    }

    return $classes;
}

it('skips every example the run does not select, by a maintainer, and listens for nothing more where it is told nothing', function (): void {
    putenv(Variable::Results->value);

    expect(loadedUnder('runner.maintainers', ['run']))->toBe([Selecting::class])
        ->and(loadedUnder('event_dispatcher.listeners', ['run']))->toBe([]);
});

it('records each selected example to the results file the gate names, and to none where it names an empty one', function (): void {
    putenv(sprintf('%s=%s/results.txt', Variable::Results->value, Scratch::directory()));
    $named = loadedUnder('event_dispatcher.listeners', ['run']);
    putenv(sprintf('%s=', Variable::Results->value));

    expect($named)->toBe([Recording::class])
        ->and(loadedUnder('event_dispatcher.listeners', ['run']))->toBe([]);
});

it('lists the suite\'s examples where it is told to', function (): void {
    expect(loadedUnder('event_dispatcher.listeners', [LaunchOption::Listing->with('/l.json')]))->toBe([Listing::class]);
});

it('measures the selected examples\' coverage where it is told a file to write it to, and none where the file is empty', function (): void {
    expect(loadedUnder('event_dispatcher.listeners', [LaunchOption::Coverage->with('/m.php')]))->toBe([Covering::class])
        ->and(loadedUnder('event_dispatcher.listeners', [LaunchOption::Coverage->with('')]))->toBe([]);
})->skip(fn(): bool => ! extension_loaded('pcov') && ! extension_loaded('xdebug'), 'only a PHP with a coverage driver');

it('says nothing in the guard file where opcache is off on the command line', function (): void {
    $guard = sprintf('%s/guard.txt', Scratch::directory());
    putenv(sprintf('%s=%s', Variable::Guard->value, $guard));
    loadedUnder('runner.maintainers', ['run']);

    expect(is_file($guard))->toBeFalse();
})->skip(fn(): bool => (bool) ini_get('opcache.enable_cli'), 'only a PHP with opcache off on the command line');

it('notes in the guard file that opcache could serve an original, and only where it could and a file is named', function (): void {
    $file = sprintf('%s/guard.txt', Scratch::directory());
    Extension::noting($file, Opcache::ofCommandLine('0'));
    Extension::noting(file: false, opcache: Opcache::ofCommandLine('1'));
    Extension::noting('', Opcache::ofCommandLine('1'));
    $untouched = is_file($file);
    Extension::noting($file, Opcache::ofCommandLine('1'));
    Extension::noting($file, Opcache::ofCommandLine('1'));

    expect($untouched)->toBeFalse()
        ->and(file($file, FILE_IGNORE_NEW_LINES))->toBe(['cached', 'cached']);
});
