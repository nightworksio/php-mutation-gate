<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Php\PhpConfig;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A config file, in the directory it is in. */
$configFile = static fn(string $path): ConfigFile => ConfigFile::at(Path::of($path), Path::of(dirname($path)));

it('reads the config the file\'s builder writes', function () use ($configFile): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        use NightWorksIO\MutationGate\Config\Gate;
        use NightWorksIO\MutationGate\Config\Runner;

        return Gate::configure()->runner(Runner::infection());
        PHP);
    $layer = new PhpConfig()->load($configFile(sprintf('%s/mutation-gate.php', $project)));

    expect($layer instanceof Layer ? Configs::decoded($layer) : $layer)->toBe(['runner' => 'infection']);
});

it('refuses a file that returns anything but the builder', function (string $returns, string $type) use (
    $configFile,
): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.php', sprintf("<?php\n\nreturn %s;\n", $returns));
    $file = sprintf('%s/mutation-gate.php', $project);

    expect(new PhpConfig()->load($configFile($file)))->toEqual(CannotJudge::because(sprintf(
        '%s returns %s. It must return Gate::configure() with its settings.',
        $file,
        $type,
    )));
})->with([
    'an array' => ["['runner' => 'pest']", 'array'],
    'a closure' => ['static fn (): string => \'pest\'', 'Closure'],
    'nothing' => ['null', 'null'],
]);

it('cannot judge a file that fails as it runs, saying why', function () use ($configFile): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        return NightWorksIO\MutationGate\Config\Tree::at(3);
        PHP);
    $file = sprintf('%s/mutation-gate.php', $project);
    $loaded = new PhpConfig()->load($configFile($file));

    expect($loaded)->toBeInstanceOf(CannotJudge::class)
        ->and($loaded instanceof CannotJudge ? $loaded->why() : '')
        ->toStartWith(sprintf('%s could not be read: ', $file))
        ->and($loaded instanceof CannotJudge ? $loaded->why() : '')->toContain('must be of type string, int given');
});

it('cannot judge a file that is not there', function () use ($configFile): void {
    expect(new PhpConfig()->load($configFile('/nowhere/mutation-gate.php')))
        ->toEqual(CannotJudge::because('/nowhere/mutation-gate.php could not be read.'));
});

it('reads every problem of the config the builder writes, at its path', function () use ($configFile): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        use NightWorksIO\MutationGate\Config\Floor;
        use NightWorksIO\MutationGate\Config\Gate;

        return Gate::configure()->newCode(Floor::of(120));
        PHP);

    expect(Configs::problems(new PhpConfig()->load($configFile(sprintf('%s/mutation-gate.php', $project)))))
        ->toBe(['newCode.floor: expected a number from 0 to 100, got 120']);
});
