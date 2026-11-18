<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Plan\LeftMutant;
use NightWorksIO\MutationGate\Core\Plan\LeftoverShare;
use NightWorksIO\MutationGate\Core\Plan\LeftoverShares;
use NightWorksIO\MutationGate\Core\Plan\ShardId;

/** The id of the mutant on this line of src/Money.php. */
function leftoverShareId(int $line): MutantId
{
    return MutantId::hash(Path::of('src/Money.php'), 'Minus', '- a + b', $line);
}

/** The mutant on this line of src/Money.php, as a share lists it. */
function leftoverShareMutant(int $line): LeftMutant
{
    return LeftMutant::of(leftoverShareId($line), sprintf('Minus-%d', $line), Path::of(sprintf('src/M%d.php', $line % 2)));
}

it('reads back the shares it wrote, each with its shard, number and mutants, by shard', function (): void {
    $plan = Digest::sha256Of('plan');
    $written = LeftoverShares::cut(
        $plan,
        LeftoverShare::of(ShardId::of(2), 1, leftoverShareMutant(1), leftoverShareMutant(2), leftoverShareMutant(3)),
        LeftoverShare::of(ShardId::of(5), 1, leftoverShareMutant(4)),
        LeftoverShare::of(ShardId::of(2), 2),
    );
    $read = LeftoverShares::decode($written->encode());
    $shares = $read instanceof LeftoverShares ? $read->ofShard(ShardId::of(2)) : [];

    expect($read)->toEqual($written)
        ->and($read instanceof LeftoverShares && $read->cutFrom($plan))->toBeTrue()
        ->and($read instanceof LeftoverShares && $read->cutFrom(Digest::sha256Of('another')))->toBeFalse()
        ->and(array_map(static fn(LeftoverShare $share): array => [
            $share->shard()->number(),
            $share->number(),
            array_map(static fn(MutantId $id): string => $id->value(), [...$share->mutants()]),
            $share->natives(),
            array_map(static fn(Path $file): string => $file->value(), [...$share->files()]),
        ], $shares))->toBe([
            [2, 1, [leftoverShareId(1)->value(), leftoverShareId(2)->value(), leftoverShareId(3)->value()], ['Minus-1', 'Minus-2', 'Minus-3'], ['src/M1.php', 'src/M0.php']],
            [2, 2, [], [], []],
        ])
        ->and($read instanceof LeftoverShares ? $read->ofShard(ShardId::of(9)) : null)->toBe([]);
});

it('cannot read shares of another format, or not shaped as shares', function (string $json, string $why): void {
    expect(LeftoverShares::decode($json))
        ->toEqual(CannotJudge::because(sprintf('The shares of the mutants the shards left cannot be read: %s', $why)));
})->with([
    'another format' => ['{"format": 2, "plan": "a", "shares": []}', 'the file.format is not format 1.'],
    'no list' => ['{"format": 1, "plan": "a", "shares": "x"}', 'the file.shares is not a list.'],
]);
