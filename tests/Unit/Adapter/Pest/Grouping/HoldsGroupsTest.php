<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Grouping\HoldsGroups;
use NightWorksIO\MutationGate\Attribute\Holds;
use Pest\Factories\Attribute;
use Pest\Factories\TestCaseMethodFactory;
use Pest\PendingCalls\DescribeCall;
use Pest\Repositories\TestRepository;
use Pest\Support\Description;
use Pest\TestSuite;
use PHPUnit\Framework\Attributes\Group;

/**
 * The groups a test is in once the filter has seen it.
 *
 * @return list<string>
 */
function groupingGroups(TestCaseMethodFactory $factory): array
{
    $groups = [];

    foreach ($factory->attributes as $attribute) {
        foreach ($attribute->name === Group::class ? $attribute->arguments : [] as $group) {
            $groups[] = $group;
        }
    }

    return $groups;
}

it('adds a holds group for each #[Holds] on a test\'s closure, and keeps the test', function (): void {
    $factory = new TestCaseMethodFactory(
        'tests/MoneyTest.php',
        #[Holds('src/Money.php')]
        #[Holds('src/Held.php')]
        static fn(): bool => true,
    );

    expect(new HoldsGroups()->accept($factory))->toBeTrue()
        ->and(groupingGroups($factory))->toBe(['holds:src/Money.php', 'holds:src/Held.php']);
});

it('adds a group once, beside the groups the test is in already', function (): void {
    $factory = new TestCaseMethodFactory(
        'tests/MoneyTest.php',
        #[Holds('src/Money.php')]
        #[Holds('src/Money.php')]
        static function (): void {
        },
    );
    $factory->attributes[] = new Attribute(Group::class, ['slow']);
    $factory->attributes[] = new Attribute(Group::class, ['holds:src/Money.php']);

    new HoldsGroups()->accept($factory);

    expect(groupingGroups($factory))->toBe(['slow', 'holds:src/Money.php']);
});

it('adds nothing to a test whose closure holds nothing', function (): void {
    $factory = new TestCaseMethodFactory('tests/MoneyTest.php', static fn(): bool => true);

    expect(new HoldsGroups()->accept($factory))->toBeTrue()
        ->and($factory->attributes)->toBe([]);
});

it('adds the group of every describe a test is registered inside, at any depth', function (): void {
    $factory = new TestCaseMethodFactory('tests/MoneyTest.php', #[Holds('src/Money.php')] static fn(): bool => true);
    $suite = TestSuite::getInstance();

    new DescribeCall(
        $suite,
        'tests/MoneyTest.php',
        new Description('money'),
        #[Holds('src/Held.php')]
        static function () use ($suite, $factory): void {
            new DescribeCall(
                $suite,
                'tests/MoneyTest.php',
                new Description('adding'),
                #[Holds('src/Kernel.php')]
                static function () use ($factory): void {
                    new HoldsGroups()->accept($factory);
                },
            );
        },
    );

    expect(groupingGroups($factory))->toBe(['holds:src/Money.php', 'holds:src/Kernel.php', 'holds:src/Held.php']);
});

it('filters every test Pest registers from then on', function (): void {
    $tests = new TestRepository();
    HoldsGroups::register($tests);
    // Pest refuses a static closure for a test it builds.
    $factory = new TestCaseMethodFactory('tests/MoneyTest.php', #[Holds('src/Money.php')] fn(): bool => true);
    $factory->description = 'adds';

    $tests->set($factory);

    expect(groupingGroups($factory))->toBe(['holds:src/Money.php'])
        ->and($tests->count())->toBe(1);
});
