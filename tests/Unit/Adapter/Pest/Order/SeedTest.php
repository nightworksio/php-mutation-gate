<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\CoverageFile;
use NightWorksIO\MutationGate\Adapter\Pest\Order\Seed;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Tests\Support\CoverageMaps;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('keeps a mutant\'s order in a directory of its own, by the name of its mutated copy', function (): void {
    expect(Seed::directoryOf('/o', '/tmp/mutations/abc'))->toBe('/o/abc');
});

it('writes the first likely killer as an error, the rest as failures, and each covering test\'s time', function (): void {
    $root = Scratch::directory();
    $map = sprintf('%s/coverage.php', $root);
    CoverageMaps::write($map, $root, ['src/A.php' => [3 => [0, 1]]], ['T::a', 'T::b#0'], ['T::a' => 0.5]);
    $coverage = CoverageFile::at($map);
    $seed = sprintf('%s/seed', $root);

    if ($coverage instanceof CoverageFile) {
        Seed::write($seed, '5.1.0', TestIds::of(TestId::of('T::b#0'), TestId::of('T::c'), TestId::of('T::a')), ['T::a', 'T::b#0'], $coverage);
        Seed::write(sprintf('%s/empty', $root), '5.1.0', TestIds::none(), [], $coverage);
    }

    expect(file_get_contents(sprintf('%s/test-run-history', $seed)))->toBe(
        '{"version":"pest_5.1.0","defects":{"T::b with data set #0":8,"T::c":7,"T::a":7},'
        . '"times":{"T::a":0.5,"T::b with data set #0":0.0}}',
    )->and(file_get_contents(sprintf('%s/empty/test-run-history', $root)))
        ->toBe('{"version":"pest_5.1.0","defects":{},"times":{}}');
});
