<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Installed;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitOption;
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

it('answers the versions of PHPUnit and its code coverage, from PHPUnit 13.2.0 on, and on any branch as it is', function (string $version): void {
    expect(Installed::versionsIn(installedPhpUnit($version)))->toEqual(Versions::of(
        Version::of('phpunit/phpunit', $version, 'abc'),
        Version::of('phpunit/php-code-coverage', '14.3.0', 'def'),
    ));
})->with(['13.2.0', 'v13.3.4', '14.0.0', 'dev-main', '13.2.x-dev', '13.1.x-dev']);

it('cannot judge a PHPUnit older than 13.2.0, which has no --test-id-filter-file', function (string $version, string $said): void {
    $manifest = installedPhpUnit($version);

    expect(Installed::versionsIn($manifest))->toEqual(CannotJudge::because(sprintf(
        'The phpunit runner needs PHPUnit 13.2.0 or later, for --test-id-filter-file, and %s holds %s.',
        $manifest,
        $said,
    )));
})->with([['13.1.9', '13.1.9'], ['v12.5.8', '12.5.8']]);

it('cannot judge where Composer installed nothing, naming PHPUnit as the runner it cannot say', function (): void {
    $manifest = sprintf('%s/none.json', Scratch::directory());

    expect(Installed::versionsIn($manifest))->toEqual(CannotJudge::because(sprintf(
        '%s does not list phpunit/phpunit, phpunit/php-code-coverage, so the gate cannot say which PHPUnit judges '
        . 'the mutants. Run composer install.',
        $manifest,
    )));
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

it('leaves the test run history as it was with the option the installed PHPUnit names it by', function (string $version, PhpUnitOption $option): void {
    expect(Installed::historyIn(installedPhpUnit($version)))->toBe($option);
})->with([
    'before the history' => ['13.2.9', PhpUnitOption::DoNotCacheResult],
    'a tag before it' => ['v13.2.0', PhpUnitOption::DoNotCacheResult],
    'the first with it' => ['13.3.0', PhpUnitOption::DoNotRecordTestRunHistory],
    'a later one' => ['14.0.0', PhpUnitOption::DoNotRecordTestRunHistory],
    'a branch' => ['13.2.x-dev', PhpUnitOption::DoNotRecordTestRunHistory],
]);

it('names the history\'s own option where it cannot read which PHPUnit is installed', function (string $text): void {
    $root = Scratch::directory();
    Scratch::write($root, 'installed.json', $text);

    expect(Installed::historyIn(sprintf('%s/installed.json', $root)))->toBe(PhpUnitOption::DoNotRecordTestRunHistory)
        ->and(Installed::historyIn(sprintf('%s/none.json', $root)))->toBe(PhpUnitOption::DoNotRecordTestRunHistory);
})->with(['not JSON' => ['{'], 'no PHPUnit' => [fn(): string => (string) json_encode(['packages' => []])]]);
