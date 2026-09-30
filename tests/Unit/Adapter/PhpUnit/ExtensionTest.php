<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Extension;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Variable;
use PHPUnit\Runner\Extension\ExtensionFacade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Registry;

it('records nothing in a PHPUnit the gate did not start for a mutant', function (): void {
    putenv(Variable::Results->value);

    new Extension()->bootstrap(Registry::get(), new ExtensionFacade(), ParameterCollection::fromArray([]));

    expect(getenv(Variable::Results->value))->toBeFalse();
});
