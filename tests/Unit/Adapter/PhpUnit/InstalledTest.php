<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Installed;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** Composer's record of a vendor directory holding this PHPUnit and its code coverage. */
function installedPhpUnit(string $version): string
{
    $root = Scratch::directory();
    Scratch::write($root, 'installed.json', (string) json_encode(['packages' => [
        ['name' => 'phpunit/phpunit', 'version' => $version, 'source' => ['reference' => 'abc']],
        ['name' => 'phpunit/php-code-coverage', 'version' => '14.3.0', 'source' => ['reference' => 'def']],
    ]]));

    return sprintf('%s/installed.json', $root);
}

it('answers the versions of PHPUnit and its code coverage, from PHPUnit 13.2.0 on', function (string $version): void {
    expect(Installed::versionsIn(installedPhpUnit($version)))->toEqual(Versions::of(
        Version::of('phpunit/phpunit', $version, 'abc'),
        Version::of('phpunit/php-code-coverage', '14.3.0', 'def'),
    ));
})->with(['13.2.0', 'v13.3.4', '14.0.0', 'dev-main']);

it('cannot judge a PHPUnit older than 13.2.0, which has no --test-id-filter-file', function (string $version, string $said): void {
    $manifest = installedPhpUnit($version);

    expect(Installed::versionsIn($manifest))->toEqual(CannotJudge::because(sprintf(
        'The phpunit runner needs PHPUnit 13.2.0 or later, for --test-id-filter-file, and %s holds %s.',
        $manifest,
        $said,
    )));
})->with([['13.1.9', '13.1.9'], ['v12.5.8', '12.5.8']]);

it('cannot judge where Composer installed nothing', function (): void {
    expect(Installed::versionsIn(sprintf('%s/none.json', Scratch::directory())))->toBeInstanceOf(CannotJudge::class);
});
