<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\ToldChildren;
use NightWorksIO\MutationGate\Core\Runner\ChildVariable;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Environment;

it('tells a coverage run to record what its tests load only under reach.lockMoves: loaded', function (string $moves, string|false $told): void {
    $seen = Environment::during([ChildVariable::RecordsLoads->value => null], static function () use ($moves): object {
        ToldChildren::by(Configs::settings(['runner' => 'pest', 'reach' => ['lockMoves' => $moves]]));

        return new ArrayObject([getenv(ChildVariable::RecordsLoads->value)]);
    });

    expect($seen[0])->toBe($told);
})->with([
    'loaded' => ['loaded', '1'],
    'named' => ['named', false],
    'everything' => ['everything', false],
]);
