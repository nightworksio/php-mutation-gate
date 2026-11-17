<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\EarlyStreams;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamSettings;
use NightWorksIO\MutationGate\Tests\Support\Mutations;
use Pest\Mutate\Support\MutationTestResult;

/**
 * What each mutant of a suite of two came to once its early streams judged it with pcov so enabled, and whether a
 * stream directory was made for it.
 *
 * @return array{list<MutationTestResult>, bool}
 */
function earlyJudged(): array
{
    $suite = Mutations::suite('src/Money.php', MutationTestResult::None, MutationTestResult::None);
    $before = earlyStreamDirectories();

    EarlyStreams::judge($suite, [], 1, StreamSettings::of(enabled: true, children: 1, changeCheck: true, killersFirst: true));
    $results = [];

    foreach ($suite->repository->all() as $collection) {
        foreach ($collection->tests() as $test) {
            $results[] = $test->result();
        }
    }

    return [$results, earlyStreamDirectories() !== $before];
}

/** @return list<string> every stream directory in the system's temporary directory */
function earlyStreamDirectories(): array
{
    $found = glob(sprintf('%s/mutation-gate-split-*', sys_get_temp_dir()));

    return $found === false ? [] : $found;
}

it('streams nothing where no mutant has a site its schema switches, whether pcov can collect a test\'s lines or not', function (): void {
    expect(earlyJudged())->toBe([[MutationTestResult::None, MutationTestResult::None], false]);
});
