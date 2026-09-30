<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\IdPrefix;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;

it('names every id that starts with it, a whole id naming itself', function (): void {
    $id = MutantId::hash(Path::of('src/Money.php'), 'Plus', '-a', 0);
    $prefix = IdPrefix::parse(mb_substr($id->value(), 0, 6));
    $whole = IdPrefix::parse($id->value());
    $other = IdPrefix::parse($id->value() === 'ffffffffffff' ? 'eeeeee' : 'ffffff');

    expect($prefix instanceof IdPrefix && $prefix->names($id))->toBeTrue()
        ->and($whole instanceof IdPrefix && $whole->names($id))->toBeTrue()
        ->and($whole instanceof IdPrefix ? $whole->value() : $whole)->toBe($id->value())
        ->and($other instanceof IdPrefix && $other->names($id))->toBeFalse();
});

it('refuses what is not six to twelve lowercase hex characters', function (string $written): void {
    expect(IdPrefix::parse($written))->toEqual(CannotJudge::because(sprintf(
        '"%s" is not a mutant id. Give the twelve lowercase hex characters every report prints, or the first six or more.',
        $written,
    )));
})->with(['five' => ['3f9a1'], 'thirteen' => ['3f9a1c2b7d04a'], 'upper case' => ['3F9A1C'], 'not hex' => ['3f9a1g'], 'a line after' => ["3f9a1c\n"]]);
