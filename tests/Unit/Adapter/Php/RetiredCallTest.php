<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Php\RetiredCall;
use NightWorksIO\MutationGate\Core\Migration\Migration;
use NightWorksIO\MutationGate\Core\Migration\Migrations;
use NightWorksIO\MutationGate\Core\Migration\Remove;
use NightWorksIO\MutationGate\Core\Migration\Spelling;
use NightWorksIO\MutationGate\Core\NotGiven;
use PhpParser\Node\Expr\Variable;

it('finds no step that retires what is no call', function (): void {
    $migrations = Migrations::of(Migration::in('2.0.0', Remove::of('legacy', 'nothing reads it')->spelt(Spelling::retiring('Gate::legacy'))));

    expect(RetiredCall::of(new Variable('legacy'), $migrations))->toEqual(NotGiven::value());
});
