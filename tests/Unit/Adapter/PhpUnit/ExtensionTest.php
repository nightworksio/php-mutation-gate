<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Extension;
use NightWorksIO\MutationGate\Core\Native\Variable;
use NightWorksIO\MutationGate\Core\Runner\Opcache;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use PHPUnit\Runner\Extension\ExtensionFacade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Registry;

afterEach(function (): void {
    putenv(Variable::Guard->value);
    Scratch::sweep();
});

it('records nothing in a PHPUnit the gate did not start for a mutant', function (): void {
    putenv(Variable::Results->value);

    new Extension()->bootstrap(Registry::get(), new ExtensionFacade(), ParameterCollection::fromArray([]));

    expect(getenv(Variable::Results->value))->toBeFalse();
});

it('says nothing in the guard file where opcache is off on the command line', function (): void {
    $guard = sprintf('%s/guard.txt', Scratch::directory());
    putenv(sprintf('%s=%s', Variable::Guard->value, $guard));

    new Extension()->bootstrap(Registry::get(), new ExtensionFacade(), ParameterCollection::fromArray([]));

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
