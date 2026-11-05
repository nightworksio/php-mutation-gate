<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\OpcacheBlacklist;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('keeps out each file the run mutates, the folder pest-plugin-mutate copies mutants to and the gate\'s own directory, each resolved where it is there', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'src/Money.php', '<?php');
    Scratch::write($root, 'vendor/pestphp/pest-plugin-mutate/.temp/abc', '<?php');
    mkdir(sprintf('%s/.mutation-gate', $root));
    $real = (string) realpath($root);
    $project = Project::at($root, Paths::none(), Path::of('.mutation-gate'), Path::of('vendor'));
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php'), Path::of('src/Gone.php')), WholeSuite::tests());
    $warm = Scratch::directory();

    OpcacheBlacklist::of($project, $request)->leftIn($warm);

    expect(file_get_contents(sprintf('%s/opcache-blacklist', $warm)))->toBe(sprintf("%s\n", implode("\n", [
        sprintf('%s/src/Money.php', $real),
        sprintf('%s/src/Gone.php', $real),
        sprintf('%s/vendor/pestphp/pest-plugin-mutate/.temp', $real),
        sprintf('%s/.mutation-gate', $real),
    ])))
        ->and(OpcacheBlacklist::isIn($warm))->toBeTrue()
        ->and(OpcacheBlacklist::options($warm))->toBe(['-d', 'opcache.enable_cli=1', '-d', sprintf('opcache.blacklist_filename=%s/opcache-blacklist', $warm)])
        ->and(OpcacheBlacklist::named(sprintf('%s/opcache-blacklist', $warm)))->toBe([
            sprintf('%s/src/Money.php', $real),
            sprintf('%s/src/Gone.php', $real),
            sprintf('%s/vendor/pestphp/pest-plugin-mutate/.temp', $real),
            sprintf('%s/.mutation-gate', $real),
        ]);
});

it('starts a worker with opcache off, and names nothing kept out, where no blacklist is there', function (): void {
    $warm = Scratch::directory();
    Scratch::write($warm, 'empty', "\n\n");

    expect(OpcacheBlacklist::isIn($warm))->toBeFalse()
        ->and(OpcacheBlacklist::options($warm))->toBe([])
        ->and(OpcacheBlacklist::named(file: false))->toBe([])
        ->and(OpcacheBlacklist::named(''))->toBe([])
        ->and(OpcacheBlacklist::named(sprintf('%s/missing', $warm)))->toBe([])
        ->and(OpcacheBlacklist::named(sprintf('%s/empty', $warm)))->toBe([]);
});
