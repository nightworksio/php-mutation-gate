<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\InstalledVerdictCode;
use NightWorksIO\MutationGate\Core\Proof\Key\VerdictCode;
use NightWorksIO\MutationGate\Core\ThisPackage;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

beforeEach(function (): void {
    Scratch::entered();
});

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * The gate's code at these package roots, each file of which holds what it is given.
 *
 * @param array<string, array<string, string>> $packages each package's files, by its name
 */
function installedAt(array $packages): InstalledVerdictCode
{
    $roots = [];

    foreach ($packages as $name => $files) {
        $root = Scratch::directory();

        foreach ($files as $path => $contents) {
            Scratch::write($root, $path, $contents);
        }

        $roots[$name] = $root;
    }

    return InstalledVerdictCode::at(
        static fn(string $package): string => $roots[$package],
        time(...),
        ...array_map(strval(...), array_keys($roots)),
    );
}

$gate = [
    'src/Core/Mutant/Mutant.php' => '<?php // a mutant',
    'src/Cli/Command/Show.php' => '<?php // a command',
    'bin/mutation-gate-worker' => '<?php // the worker',
];

it('digests the code a runner\'s results depend on alike each time, and another runner\'s apart', function (): void {
    $installed = InstalledVerdictCode::installed();

    expect($installed->of('pest'))->toEqual($installed->of('pest'))
        ->and($installed->of('pest'))->not->toEqual($installed->of('infection'))
        ->and($installed->of('acme'))->not->toEqual($installed->of('pest'));
});

it('moves with a file of a set the runner reads, a file a glob names whole included, and not with one outside', function () use ($gate): void {
    $digest = installedAt([ThisPackage::COMPOSER => $gate])->of('phpunit');

    expect(installedAt([ThisPackage::COMPOSER => [...$gate, 'src/Core/Mutant/Mutant.php' => '<?php // moved']])->of('phpunit'))
        ->not->toEqual($digest)
        ->and(installedAt([ThisPackage::COMPOSER => [...$gate, 'bin/mutation-gate-worker' => '<?php // moved']])->of('phpunit'))
        ->not->toEqual($digest)
        ->and(installedAt([ThisPackage::COMPOSER => [...$gate, 'src/Cli/Command/Show.php' => '<?php // moved']])->of('phpunit'))
        ->toEqual($digest);
});

it('reads the code of each of the gate\'s mutator packages installed, and nothing of a package not installed', function () use ($gate): void {
    $alone = installedAt([ThisPackage::COMPOSER => $gate])->of('pest');
    $plugin = sprintf('%sacme', VerdictCode::PLUGINS);
    $withPlugin = installedAt([ThisPackage::COMPOSER => $gate, $plugin => ['src/Acme.php' => '<?php // acme']])->of('pest');
    $notInstalled = InstalledVerdictCode::at(
        static fn(): null => null,
        time(...),
        ThisPackage::COMPOSER,
        $plugin,
    )->of('pest');

    expect($withPlugin)->not->toEqual($alone)
        ->and(installedAt([ThisPackage::COMPOSER => $gate, $plugin => ['src/Acme.php' => '<?php // moved']])->of('pest'))
        ->not->toEqual($withPlugin)
        ->and(installedAt([ThisPackage::COMPOSER => $gate, 'acme/other' => ['src/Acme.php' => '<?php // acme']])->of('pest'))
        ->toEqual($alone)
        ->and($notInstalled)->toEqual(InstalledVerdictCode::at(static fn(): null => null, time(...))->of('pest'));
});

it('moves with every file of a mutator package, and with the gate\'s own code beside one', function () use ($gate): void {
    $plugin = sprintf('%sacme', VerdictCode::PLUGINS);
    $code = ['src/Acme.php' => '<?php // acme', 'src/Other.php' => '<?php // other'];
    $digest = installedAt([ThisPackage::COMPOSER => $gate, $plugin => $code])->of('pest');

    expect(installedAt([ThisPackage::COMPOSER => $gate, $plugin => [...$code, 'src/Acme.php' => '<?php // moved']])->of('pest'))
        ->not->toEqual($digest)
        ->and(installedAt([ThisPackage::COMPOSER => $gate, $plugin => [...$code, 'src/Other.php' => '<?php // moved']])->of('pest'))
        ->not->toEqual($digest)
        ->and(installedAt([ThisPackage::COMPOSER => [...$gate, 'src/Core/Mutant/Mutant.php' => '<?php // moved'], $plugin => $code])->of('pest'))
        ->not->toEqual($digest);
});

it('digests the same code alike, whichever order its packages are installed in', function () use ($gate): void {
    $acme = sprintf('%sacme', VerdictCode::PLUGINS);
    $zeta = sprintf('%szeta', VerdictCode::PLUGINS);

    expect(installedAt([ThisPackage::COMPOSER => $gate, $acme => ['src/A.php' => '<?php // a'], $zeta => ['src/Z.php' => '<?php // z']])->of('pest'))
        ->toEqual(installedAt([$zeta => ['src/Z.php' => '<?php // z'], ThisPackage::COMPOSER => $gate, $acme => ['src/A.php' => '<?php // a']])->of('pest'));
});

it('digests the gate\'s code again where a file of it is written in the second it was digested, its size kept', function () use ($gate): void {
    $root = Scratch::directory();

    foreach ($gate as $path => $contents) {
        Scratch::write($root, $path, $contents);
    }

    $installed = InstalledVerdictCode::at(static fn(): string => $root, time(...), ThisPackage::COMPOSER);
    $digest = $installed->of('phpunit');
    Scratch::write($root, 'src/Core/Mutant/Mutant.php', '<?php // b mutant');

    expect($installed->of('phpunit'))->not->toEqual($digest)
        ->and($installed->of('phpunit'))->toEqual(installedAt([ThisPackage::COMPOSER => [...$gate, 'src/Core/Mutant/Mutant.php' => '<?php // b mutant']])->of('phpunit'));
});

it('digests the gate\'s code it kept again once a file of it is added, written or removed', function () use ($gate): void {
    $root = Scratch::directory();

    foreach ($gate as $path => $contents) {
        Scratch::write($root, $path, $contents);
    }

    $installed = InstalledVerdictCode::at(static fn(): string => $root, static fn(): int => time() + 60, ThisPackage::COMPOSER);
    $digest = $installed->of('phpunit');
    $kept = $installed->of('phpunit');
    Scratch::write($root, 'src/Core/Mutant/Added.php', '<?php // added');
    $added = $installed->of('phpunit');
    unlink(sprintf('%s/src/Core/Mutant/Added.php', $root));
    $removed = $installed->of('phpunit');
    Scratch::write($root, 'src/Core/Mutant/Mutant.php', '<?php // a longer mutant');
    $written = $installed->of('phpunit');

    expect($kept)->toEqual($digest)
        ->and($added)->toEqual(installedAt([ThisPackage::COMPOSER => [...$gate, 'src/Core/Mutant/Added.php' => '<?php // added']])->of('phpunit'))
        ->and($removed)->toEqual($digest)
        ->and($written)->toEqual(installedAt([ThisPackage::COMPOSER => [...$gate, 'src/Core/Mutant/Mutant.php' => '<?php // a longer mutant']])->of('phpunit'))
        ->and($written)->not->toEqual($digest);
});

it('reads a link to a file as the file it names, and nothing under a link to a directory', function () use ($gate): void {
    $root = Scratch::directory();
    $elsewhere = Scratch::directory();

    foreach ($gate as $path => $contents) {
        Scratch::write($root, $path, $contents);
    }

    Scratch::write($elsewhere, 'Linked.php', '<?php // linked');
    Scratch::write($elsewhere, 'Deeper/Under.php', '<?php // under');
    symlink(sprintf('%s/Linked.php', $elsewhere), sprintf('%s/src/Core/Mutant/Linked.php', $root));
    symlink(sprintf('%s/Deeper', $elsewhere), sprintf('%s/src/Core/Mutant/Deeper', $root));

    expect(InstalledVerdictCode::at(static fn(): string => $root, time(...), ThisPackage::COMPOSER)->of('phpunit'))
        ->toEqual(installedAt([ThisPackage::COMPOSER => [...$gate, 'src/Core/Mutant/Linked.php' => '<?php // linked']])->of('phpunit'));
});
