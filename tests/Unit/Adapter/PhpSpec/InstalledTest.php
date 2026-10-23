<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpSpec\Installed;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** Composer's record of a vendor directory holding this PhpSpec and this code coverage. */
function installedPhpSpec(string $phpspec, string $coverage = '14.4.1'): string
{
    $root = Scratch::directory();
    Scratch::write($root, 'installed.json', (string) json_encode(['packages' => [
        ['name' => 'phpspec/phpspec', 'version' => $phpspec, 'source' => ['reference' => 'abc']],
        ['name' => 'phpunit/php-code-coverage', 'version' => $coverage, 'source' => ['reference' => 'def']],
    ]]));

    return sprintf('%s/installed.json', $root);
}

it('answers the versions of PhpSpec and its code coverage, from PhpSpec 8.2.0 and php-code-coverage 14.0.0 on, and on any branch as it is', function (string $phpspec, string $coverage): void {
    expect(Installed::versionsIn(installedPhpSpec($phpspec, $coverage)))->toEqual(Versions::of(
        Version::of('phpspec/phpspec', $phpspec, 'abc'),
        Version::of('phpunit/php-code-coverage', $coverage, 'def'),
    ));
})->with([['8.2.0', '14.0.0'], ['v8.3.1', '14.4.1'], ['dev-main', '14.4.1'], ['8.3.1', '13.0.x-dev']]);

it('cannot judge a PhpSpec or a php-code-coverage older than the runner drives', function (string $phpspec, string $coverage, string $package, string $floor, string $said): void {
    $manifest = installedPhpSpec($phpspec, $coverage);

    expect(Installed::versionsIn($manifest))->toEqual(CannotJudge::because(sprintf(
        'The phpspec runner needs %s %s or later, and %s holds %s.',
        $package,
        $floor,
        $manifest,
        $said,
    )));
})->with([
    'PhpSpec 8.1' => ['8.1.9', '14.4.1', 'phpspec/phpspec', '8.2.0', '8.1.9'],
    'php-code-coverage 13' => ['8.3.1', '13.0.0', 'phpunit/php-code-coverage', '14.0.0', '13.0.0'],
]);

it('cannot judge where Composer installed nothing, naming PhpSpec as the runner it cannot say', function (): void {
    $manifest = sprintf('%s/none.json', Scratch::directory());
    $judged = Installed::versionsIn($manifest);

    expect($judged instanceof CannotJudge ? $judged->why() : $judged)->toContain('which PhpSpec judges the mutants');
});

it('cannot judge with a manifest that is not JSON, or that holds no list of packages', function (string $text): void {
    $root = Scratch::directory();
    Scratch::write($root, 'installed.json', $text);
    $file = sprintf('%s/installed.json', $root);

    expect(Installed::versionsIn($file))->toEqual(CannotJudge::because(sprintf(
        '%s is not the list of installed packages Composer 2 writes, so what it installed cannot be read.',
        $file,
    )));
})->with(['{', '{"packages": "none"}']);
