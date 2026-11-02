<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Testo\Installed;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** Composer's record of a vendor directory holding this Testo and its coverage plugin. */
function installedTesto(string $testo, string $codecov = '0.2.5'): string
{
    $root = Scratch::directory();
    Scratch::write($root, 'installed.json', (string) json_encode(['packages' => [
        ['name' => 'testo/testo', 'version' => $testo, 'source' => ['reference' => 'abc']],
        ['name' => 'testo/codecov', 'version' => $codecov, 'source' => ['reference' => 'def']],
    ]]));

    return sprintf('%s/installed.json', $root);
}

it('answers the versions of Testo and its coverage plugin, Testo of its range alone, and on any branch as it is', function (string $testo): void {
    expect(Installed::versionsIn(installedTesto($testo)))->toEqual(Versions::of(
        Version::of('testo/testo', $testo, 'abc'),
        Version::of('testo/codecov', '0.2.5', 'def'),
    ));
})->with(['0.10.56', 'v0.10.57', '0.10.99', 'dev-main', '0.11.x-dev']);

it('cannot judge a Testo outside the range the runner drives', function (string $testo, string $said): void {
    $manifest = installedTesto($testo);

    expect(Installed::versionsIn($manifest))->toEqual(CannotJudge::because(sprintf(
        'The testo runner drives Testo 0.10.56 or a later 0.10.* release, and %s holds %s.',
        $manifest,
        $said,
    )));
})->with([
    'an older one' => ['0.9.4', '0.9.4'],
    'one of its series below its floor' => ['0.10.55', '0.10.55'],
    'one below its floor, spelt with a v' => ['v0.10.5', '0.10.5'],
    'a newer one' => ['0.11.0', '0.11.0'],
    'one with no zero' => ['1.10.0', '1.10.0'],
]);

it('cannot judge where Composer installed nothing, naming Testo as the runner it cannot say', function (): void {
    $manifest = sprintf('%s/none.json', Scratch::directory());
    $judged = Installed::versionsIn($manifest);

    expect($judged instanceof CannotJudge ? $judged->why() : $judged)->toContain('which Testo judges the mutants');
});

it('cannot judge where Composer installed Testo without its coverage plugin', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'installed.json', (string) json_encode(['packages' => [
        ['name' => 'testo/testo', 'version' => '0.10.56', 'source' => ['reference' => 'abc']],
    ]]));
    $judged = Installed::versionsIn(sprintf('%s/installed.json', $root));

    expect($judged instanceof CannotJudge ? $judged->why() : $judged)->toContain('testo/codecov');
});

it('cannot judge where Composer\'s record of the vendor directory is not JSON', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'installed.json', '{');

    expect(Installed::versionsIn(sprintf('%s/installed.json', $root)))->toBeInstanceOf(CannotJudge::class);
});
