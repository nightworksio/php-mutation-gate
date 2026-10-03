<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Config\Chosen;
use NightWorksIO\MutationGate\Cli\FirstParty;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Explaining;
use NightWorksIO\MutationGate\Cli\Flow\Reporting;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Mutant\IdPrefix;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('cannot explain a mutant or a cluster where it cannot tell where the checkout stands', function (string $id): void {
    $composed = new Composed(
        Flows::settings(),
        Flows::adapters(Flows::project(), [], Flows::lost()),
        Flows::setup(),
        new Reporting(new Chosen(new FirstParty()->extend(new Extensions(Origin::of(FirstParty::PACKAGE)))), Variables::of([])),
    );
    $sought = IdPrefix::parse($id);
    $explained = $sought instanceof CannotJudge ? $sought : new Explaining($composed)->explain($sought);

    expect($explained instanceof CannotJudge ? $explained->why() : $explained)
        ->toBe('The commit HEAD is at cannot be read, so the run cannot be tied to one. git is not installed.');
})->with(['49e02f', 'c0123456789a']);
