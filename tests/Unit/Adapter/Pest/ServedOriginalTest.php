<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\Printed;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\ServedOriginal;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A project holding src/Money.php as written, not as Pest prints it. */
function servedProject(): Project
{
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'src/Money.php', "<?php\n\n// Money.\nfinal class Money {   public function add(int \$a, int \$b): int { return \$a + \$b; } }\n");

    return Project::at($root, Paths::none(), Path::of('.mutation-gate'), Path::of('vendor'));
}

it('serves a file unmutated as Pest prints its mutants, through Pest\'s override in the file\'s own place', function (): void {
    $at = servedProject();
    $printed = Printed::of(Contents::of((string) file_get_contents(sprintf('%s/src/Money.php', $at->root()))), Path::of('src/Money.php'));
    $text = $printed instanceof Contents ? $printed->text() : '';
    $copy = sprintf('%s/run/originals/%s.php', $at->root(), hash('xxh3', $text));

    $served = ServedOriginal::of($at, sprintf('%s/run', $at->root()), Path::of('src/Money.php'));

    $environment = $served instanceof ServedOriginal ? $served->onto(Command::pest('pest', Withheld::standard()))->environment() : [];

    expect(array_intersect_key($environment, ['PEST_MUTATION_TESTING' => true, 'PEST_MUTATION_FILE' => true]))->toBe([
        'PEST_MUTATION_TESTING' => sprintf('%s/src/Money.php', $at->root()),
        'PEST_MUTATION_FILE' => $copy,
    ])
        ->and(file_get_contents($copy))->toBe($text)
        ->and($text)->not->toBe(file_get_contents(sprintf('%s/src/Money.php', $at->root())))
        ->and($served instanceof ServedOriginal ? $served->key() : '')->toBe(sprintf('%s/src/Money.php => %s', $at->root(), $copy));
});

it('writes each print by its own name, so a file changed since is served as it reads now', function (): void {
    $at = servedProject();
    $first = ServedOriginal::of($at, $at->root(), Path::of('src/Money.php'));
    Scratch::write($at->root(), 'src/Money.php', "<?php\n\nfinal class Money\n{\n}\n");
    $second = ServedOriginal::of($at, $at->root(), Path::of('src/Money.php'));

    expect([$first, $second])->each->toBeInstanceOf(ServedOriginal::class)
        ->and($first instanceof ServedOriginal && $second instanceof ServedOriginal && $first->key() !== $second->key())->toBeTrue();
});

it('cannot serve a file that is gone, does not parse, or whose copy cannot be written', function (string $file, string $why): void {
    /** @var non-empty-string $why */
    $at = servedProject();
    Scratch::write($at->root(), 'src/Broken.php', "<?php\n\nfinal class {\n");
    mkdir(sprintf('%s/locked/originals', $at->root()), recursive: true);
    chmod(sprintf('%s/locked/originals', $at->root()), 0500);

    $served = ServedOriginal::of($at, sprintf('%s/%s', $at->root(), $file === 'src/Money.php' ? 'locked' : 'run'), Path::of($file));
    chmod(sprintf('%s/locked/originals', $at->root()), 0700);

    expect($served instanceof CannotJudge ? $served->why() : $served)->toStartWith($why);
})->with([
    'gone' => ['src/Gone.php', 'The gate cannot read src/Gone.php to serve it unmutated.'],
    'not parsing' => ['src/Broken.php', 'src/Broken.php does not parse'],
    'unwritable' => ['src/Money.php', 'The gate cannot write '],
]);

it('serves a copy it has written already, though no copy could be written now', function (): void {
    $at = servedProject();
    $first = ServedOriginal::of($at, $at->root(), Path::of('src/Money.php'));
    chmod(sprintf('%s/originals', $at->root()), 0500);
    $again = ServedOriginal::of($at, $at->root(), Path::of('src/Money.php'));
    chmod(sprintf('%s/originals', $at->root()), 0700);

    expect($again)->toEqual($first)
        ->and($again)->toBeInstanceOf(ServedOriginal::class);
});
