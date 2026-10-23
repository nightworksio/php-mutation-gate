<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Codeception\Installed;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** Composer's record of a vendor directory holding this Codeception and this code coverage. */
function installedCodeception(string $codeception, string $coverage = '14.4.1'): string
{
    $root = Scratch::directory();
    Scratch::write($root, 'installed.json', (string) json_encode(['packages' => [
        ['name' => 'codeception/codeception', 'version' => $codeception, 'source' => ['reference' => 'abc']],
        ['name' => 'phpunit/php-code-coverage', 'version' => $coverage, 'source' => ['reference' => 'def']],
    ]]));

    return sprintf('%s/installed.json', $root);
}

it('answers the versions of Codeception and its code coverage, from Codeception 5.3.6 and php-code-coverage 14.0.0 on, and on any branch as it is', function (string $codeception, string $coverage): void {
    expect(Installed::versionsIn(installedCodeception($codeception, $coverage)))->toEqual(Versions::of(
        Version::of('codeception/codeception', $codeception, 'abc'),
        Version::of('phpunit/php-code-coverage', $coverage, 'def'),
    ));
})->with([['5.3.6', '14.0.0'], ['v5.3.7', '14.4.1'], ['dev-main', '14.4.1'], ['5.3.7', '13.0.x-dev']]);

it('cannot judge a Codeception that installs beside no php-code-coverage 14, or a php-code-coverage older than 14', function (string $codeception, string $coverage, string $package, string $floor, string $said): void {
    $manifest = installedCodeception($codeception, $coverage);

    expect(Installed::versionsIn($manifest))->toEqual(CannotJudge::because(sprintf(
        'The codeception runner needs %s %s or later, and %s holds %s.',
        $package,
        $floor,
        $manifest,
        $said,
    )));
})->with([
    'Codeception 5.3.5' => ['5.3.5', '14.4.1', 'codeception/codeception', '5.3.6', '5.3.5'],
    'php-code-coverage 13' => ['5.3.7', '13.0.0', 'phpunit/php-code-coverage', '14.0.0', '13.0.0'],
]);

it('cannot judge where Composer installed nothing, naming Codeception as the runner it cannot say', function (): void {
    $judged = Installed::versionsIn(sprintf('%s/none.json', Scratch::directory()));

    expect($judged instanceof CannotJudge ? $judged->why() : $judged)->toContain('which Codeception judges the mutants');
});
