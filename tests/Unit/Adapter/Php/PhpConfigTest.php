<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Php\PhpConfig;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Document;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('reads the config the file\'s builder writes', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        use NightWorksIO\MutationGate\Config\Gate;
        use NightWorksIO\MutationGate\Config\Runner;

        return Gate::configure()->runner(Runner::infection());
        PHP);
    $document = new PhpConfig()->load(Path::of(sprintf('%s/mutation-gate.php', $project)));

    expect($document instanceof Document ? $document->json() : $document)->toBe('{"runner":"infection"}');
});

it('refuses a file that returns anything but the builder', function (string $returns, string $type): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.php', sprintf("<?php\n\nreturn %s;\n", $returns));
    $file = sprintf('%s/mutation-gate.php', $project);

    expect(new PhpConfig()->load(Path::of($file)))->toEqual(CannotJudge::because(sprintf(
        '%s returns %s. It must return Gate::configure() with its settings.',
        $file,
        $type,
    )));
})->with([
    'an array' => ["['runner' => 'pest']", 'array'],
    'a closure' => ['static fn (): string => \'pest\'', 'Closure'],
    'nothing' => ['null', 'null'],
]);

it('cannot judge a file that fails as it runs, saying why', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        return NightWorksIO\MutationGate\Config\Tree::at(3);
        PHP);
    $file = sprintf('%s/mutation-gate.php', $project);
    $loaded = new PhpConfig()->load(Path::of($file));

    expect($loaded)->toBeInstanceOf(CannotJudge::class)
        ->and($loaded instanceof CannotJudge ? $loaded->why() : '')
        ->toStartWith(sprintf('%s could not be read: ', $file))
        ->and($loaded instanceof CannotJudge ? $loaded->why() : '')->toContain('must be of type string, int given');
});

it('cannot judge a file that is not there', function (): void {
    expect(new PhpConfig()->load(Path::of('/nowhere/mutation-gate.php')))
        ->toEqual(CannotJudge::because('/nowhere/mutation-gate.php could not be read.'));
});
