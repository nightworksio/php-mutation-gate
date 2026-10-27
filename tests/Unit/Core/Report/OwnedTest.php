<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cluster\Cluster;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Owner\CodeOwners;
use NightWorksIO\MutationGate\Core\Owner\OwnerGroup;
use NightWorksIO\MutationGate\Core\Report\Folded;
use NightWorksIO\MutationGate\Core\Report\Owned;
use NightWorksIO\MutationGate\Core\Report\OwnedPart;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\OwnerVerdicts;
use NightWorksIO\MutationGate\Tests\Support\Clustered;
use NightWorksIO\MutationGate\Tests\Support\Judged;
use NightWorksIO\MutationGate\Tests\Support\Owners;

/**
 * @param  list<OwnedPart>                          $parts
 * @return list<array{string, int, list<string>, int}> each part's group, survivors, shown items' places and items
 */
function ownedParts(array $parts): array
{
    return array_map(static function (OwnedPart $part): array {
        $group = $part->group();
        $shown = array_map(static function (JudgedMutant|Cluster $item): string {
            $judged = $item instanceof Cluster ? $item->representative() : $item;

            return sprintf('%s:%d', $judged->mutant()->location()->file()->value(), $judged->mutant()->location()->start()->number());
        }, $part->shown());

        return [$group instanceof OwnerGroup ? $group->name() : 'none', $part->survivors(), $shown, $part->items()];
    }, $parts);
}

it('puts the survivors under their owner groups, in the groups\' order, a group with none left out', function (): void {
    $survivors = Owners::trees()->mutants()->survivors(Uncovered::Count);
    $items = [...$survivors];

    expect(ownedParts(Owned::parts($survivors, $items, 20, Owners::verdicts())))->toBe([
        ['@acme/payments @jo', 2, ['src/Billing/Pay.php:2', 'src/Billing/Pay.php:3'], 2],
        ['unowned', 1, ['lib/Clock.php:5'], 1],
    ]);
});

it('shows up to this many items across the groups, in their order, and how many each has', function (int $shown, array $parts): void {
    $survivors = Owners::trees()->mutants()->survivors(Uncovered::Count);

    expect(ownedParts(Owned::parts($survivors, [...$survivors], $shown, Owners::verdicts())))->toBe($parts);
})->with([
    'one' => [1, [['@acme/payments @jo', 2, ['src/Billing/Pay.php:2'], 2], ['unowned', 1, [], 1]]],
    'two' => [2, [['@acme/payments @jo', 2, ['src/Billing/Pay.php:2', 'src/Billing/Pay.php:3'], 2], ['unowned', 1, [], 1]]],
    'none' => [0, [['@acme/payments @jo', 2, [], 2], ['unowned', 1, [], 1]]],
]);

it('counts the survivors it is given, apart from the items it shows', function (): void {
    $survivors = Owners::trees()->mutants()->survivors(Uncovered::Count);
    $items = [Judged::mutant('pay', MutantJudgement::Survived, 'src/Billing/Pay.php', 2)];

    expect(ownedParts(Owned::parts($survivors, $items, 20, Owners::verdicts())))->toBe([
        ['@acme/payments @jo', 2, ['src/Billing/Pay.php:2'], 1],
    ]);
});

it('puts a cluster under the group of its representative\'s file', function (): void {
    $survivors = Clustered::listed(Clustered::survivors()->clustered(Uncovered::Count, Clustered::sources()));
    $clusters = Clustered::verdict()->trees()->clusters();
    $owners = CodeOwners::read(Path::of('CODEOWNERS'), "/src/Cart.php @cart\n");
    $verdicts = OwnerVerdicts::of(Clustered::verdict()->trees(), $owners, Uncovered::Count);
    $parts = Owned::parts($survivors, Folded::of($survivors, $clusters), 20, $verdicts);

    expect(count($parts))->toBe(1)
        ->and($parts[0]->group() instanceof OwnerGroup ? $parts[0]->group()->name() : '')->toBe('@cart')
        ->and($parts[0]->survivors())->toBe(count($survivors))
        ->and(array_filter($parts[0]->shown(), static fn(JudgedMutant|Cluster $item): bool => $item instanceof Cluster))->not->toBe([]);
});

it('lists the items as they are, up to this many, in one part with no group where the run read no CODEOWNERS', function (): void {
    $survivors = Owners::trees()->mutants()->survivors(Uncovered::Count);
    $parts = Owned::parts($survivors, [...$survivors], 2, OwnerVerdicts::none());

    expect(ownedParts($parts))->toBe([['none', 3, ['src/Billing/Pay.php:2', 'src/Billing/Pay.php:3'], 3]])
        ->and($parts[0]->group())->toBeInstanceOf(NotGiven::class);
});
