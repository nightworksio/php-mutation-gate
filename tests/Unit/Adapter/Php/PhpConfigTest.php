<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Php\PhpConfig;
use NightWorksIO\MutationGate\Cli\Config\Php;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\ConfigReads;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
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

it('cannot judge a file that throws, saying why', function () use ($configFile): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        throw new RuntimeException('no config today');
        PHP);
    $file = sprintf('%s/mutation-gate.php', $project);

    expect(new PhpConfig()->load($configFile($file)))
        ->toEqual(CannotJudge::because(sprintf('%s could not be read: no config today', $file)));
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

/** What a config holding this reads beside itself, at this path in a project, its root written `__PROJECT__`. */
$readsOf = static function (string $code, string $at = 'mutation-gate.php'): ConfigReads {
    $project = (string) realpath(Scratch::directory());
    Scratch::write($project, $at, str_replace('__PROJECT__', $project, $code));

    return new PhpConfig()->reads(ConfigFile::at(Path::of(sprintf('%s/%s', $project, $at)), Path::of($project)));
};

/** A config that requires or reads this, then builds the gate. */
$configThat = static fn(string $statement): string => sprintf(
    "<?php\n\ndeclare(strict_types=1);\n\nuse NightWorksIO\\MutationGate\\Config\\Gate;\n\n%s\n\nreturn Gate::configure();\n",
    $statement,
);

it('reads no other file where it only builds the gate, as the gate writes a config', function () use ($readsOf): void {
    $code = Php::render(Layer::none()->php(ConfigFile::at(Path::of('/p/mutation-gate.php'), Path::of('/p'))));
    $built = <<<'PHP'
        <?php

        declare(strict_types=1);

        use NightWorksIO\MutationGate\Config\Gate;
        use NightWorksIO\MutationGate\Config\Runner;
        use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
        use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;

        return Gate::configure()
            ->runner(Runner::pest()->cappedAt(MemoryCap::of(512, MemoryUnit::Megabytes)))
            ->with(Gate::configure()->runner(Runner::uses(sprintf('%s', getenv('RUNNER') ?: 'pest'))));
        PHP;

    expect($readsOf($code))->toEqual(ConfigReads::none())
        ->and($readsOf($built))->toEqual(ConfigReads::none());
});

it('names a file it requires or includes by a literal path, where PHP finds it', function (string $statement, Paths $named) use ($readsOf, $configThat): void {
    expect($readsOf($configThat($statement), 'config/mutation-gate.php')->files())->toEqual($named);
})->with([
    'require of a path from its directory' => ["require __DIR__ . '/shared.php';", Paths::of(Path::of('config/shared.php'))],
    'require_once of one' => ["require_once __DIR__ . '/shared.php';", Paths::of(Path::of('config/shared.php'))],
    'include of one' => ["include __DIR__ . '/shared.php';", Paths::of(Path::of('config/shared.php'))],
    'include_once of one' => ["include_once __DIR__ . '/shared.php';", Paths::of(Path::of('config/shared.php'))],
    'require of a plain path, from the working directory or its own' => ["require 'shared.php';", Paths::of(Path::of('shared.php'), Path::of('config/shared.php'))],
    'require of a path through a dot' => ["require __DIR__ . '/./shared.php';", Paths::of(Path::of('config/shared.php'))],
    'require of a path from the working directory' => ["require './settings/shared.php';", Paths::of(Path::of('settings/shared.php'))],
    'require of an absolute path in the project' => ["require '__PROJECT__/settings/shared.php';", Paths::of(Path::of('settings/shared.php'))],
]);

it('names the files the files it requires require in turn, once, however they loop', function () use ($configThat): void {
    $project = (string) realpath(Scratch::directory());
    Scratch::write($project, 'mutation-gate.php', $configThat("require __DIR__ . '/a.php';"));
    Scratch::write($project, 'a.php', "<?php\n\nrequire_once __DIR__ . '/lib/b.php';\n");
    Scratch::write($project, 'lib/b.php', "<?php\n\nrequire_once __DIR__ . '/../a.php';\ninclude __DIR__ . '/gone.php';\n");
    $reads = new PhpConfig()->reads(ConfigFile::at(Path::of(sprintf('%s/mutation-gate.php', $project)), Path::of($project)));

    expect($reads)->toEqual(ConfigReads::named(Path::of('a.php'), Path::of('lib/b.php'), Path::of('a.php'), Path::of('lib/gone.php')));
});

it('stops at a file that includes itself, however its path spells it', function () use ($readsOf, $configThat): void {
    expect($readsOf($configThat("require_once __DIR__ . '/./mutation-gate.php';")))
        ->toEqual(ConfigReads::named(Path::of('mutation-gate.php')));
});

it('cannot name what it reads where it reads a file by anything but a literal include', function (string $statement, string $what) use ($readsOf, $configThat): void {
    expect($readsOf($configThat($statement))->unnamedBecause())->toBe(sprintf(
        'mutation-gate.php reads what the gate cannot name without running it (%s on line 7), so every change reaches everything.',
        $what,
    ));
})->with([
    'a require of a path it builds' => ["require __DIR__ . '/' . getenv('ENV') . '.php';", 'a `require` of a path it builds'],
    'an include of a variable' => ["\$file = 'x.php'; include \$file;", 'an `include` of a path it builds'],
    'file_get_contents' => ["\$json = file_get_contents(__DIR__ . '/settings.json');", '`file_get_contents()`'],
    'json_decode of file_get_contents' => ["\$settings = json_decode(file_get_contents('x.json'), true);", '`file_get_contents()`'],
    'glob' => ["\$files = glob(__DIR__ . '/*.php');", '`glob()`'],
    'parse_ini_file' => ["\$ini = parse_ini_file('x.ini');", '`parse_ini_file()`'],
    'a namespaced function' => ["\$x = \\Acme\\settings();", '`Acme\settings()`'],
    'a static call on a class outside the gate\'s builder' => ["\$yaml = \\Symfony\\Component\\Yaml\\Yaml::parseFile('x.yaml');", '`Symfony\Component\Yaml\Yaml`'],
    'a class outside the gate\'s builder made' => ["\$file = new \\SplFileObject('x');", '`SplFileObject`'],
    'a constant of a class outside it' => ["\$level = \\Acme\\Settings::LEVEL;", '`Acme\Settings`'],
    'an anonymous class' => ["\$settings = new class {};", '`new class`'],
    'a class it names as it runs' => ["\$class = 'Acme'; \$settings = new \$class();", 'a call by a name it holds'],
    'a call by a name it holds' => ["\$read = 'file_get_contents'; \$read('x');", 'a call by a name it holds'],
    'a static method it names as it runs' => ["\$name = 'configure'; Gate::\$name();", 'a call by a name it holds'],
    'a method it names as it runs' => ["\$gate = Gate::configure(); \$name = 'with'; \$gate->\$name();", 'a call by a name it holds'],
    'eval' => ["eval('return 1;');", '`eval`'],
    'a shell command' => ['$out = `cat settings.json`;', 'a shell command'],
    'a require outside the project' => ["require '/etc/settings.php';", 'a `require` of /etc/settings.php, outside the project'],
    'a require that climbs out of the project' => ["require __DIR__ . '/../settings.php';", 'a `require` of __DIR__/../settings.php, outside the project'],
]);

it('cannot name what it reads where it is no PHP the gate can parse', function () use ($readsOf): void {
    expect($readsOf("<?php\n\nreturn Gate::configure(\n")->unnamedBecause())
        ->toStartWith('mutation-gate.php is no PHP the gate can parse (')
        ->toEndWith('), so every change reaches everything.');
});
