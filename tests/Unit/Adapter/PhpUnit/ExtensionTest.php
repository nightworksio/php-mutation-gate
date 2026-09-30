<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Extension;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Variable;
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
})->skip((bool) ini_get('opcache.enable_cli'), 'only a PHP with opcache off on the command line');
