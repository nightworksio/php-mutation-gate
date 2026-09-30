<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Git\Command;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Tests\Support\Repository;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('answers what git printed', function (): void {
    expect(Command::in(Repository::empty()->root)->run(['rev-parse', '--is-inside-work-tree']))->toBe("true\n");
});

it('runs git with settings that keep what it prints the same', function (string $setting, string $value): void {
    expect(Command::in(Repository::empty()->root)->run(['config', $setting]))->toBe(sprintf("%s\n", $value));
})->with([
    ['core.quotePath', 'false'],
    ['diff.noprefix', 'false'],
    ['diff.mnemonicPrefix', 'false'],
    ['diff.relative', 'false'],
    ['color.ui', 'false'],
]);

it('hands git what it reads from its input', function (): void {
    expect(Command::in(Repository::empty()->root)->feed(['hash-object', '--stdin'], "hello\n"))
        ->toBe("ce013625030ba8dba906f756967f9e9ca394464a\n");
});

it('cannot tell where git gives no answer, and says what git said', function (): void {
    expect(Command::in(Repository::empty()->root)->run(['rev-parse', '--verify', 'nothing']))
        ->toEqual(CannotTell::because('git rev-parse --verify nothing gave no answer: fatal: Needed a single revision'));
});

it('cannot tell where git cannot run in the directory', function (): void {
    $missing = sprintf('%s/missing', Scratch::directory());
    $answer = Command::in($missing)->run(['status']);

    expect($answer)->toBeInstanceOf(CannotTell::class)
        ->and($answer instanceof CannotTell ? $answer->why() : '')->toBe(sprintf('git status gave no answer: The provided cwd "%s" does not exist.', $missing));
});

it('cannot tell where git stops before it reads its input, and writes nowhere it has closed', function (): void {
    $answer = Command::in(Scratch::directory())->feed(['cat-file', '--batch'], str_repeat("HEAD:./src/Money.php\n", 800_000));

    expect($answer)->toBeInstanceOf(CannotTell::class)
        ->and($answer instanceof CannotTell ? $answer->why() : '')->toStartWith('git cat-file --batch gave no answer: fatal: not a git repository');
});

it('cannot tell where git cannot be fed in the directory', function (): void {
    $missing = sprintf('%s/missing', Scratch::directory());

    expect(Command::in($missing)->feed(['hash-object', '--stdin'], "hello\n"))
        ->toEqual(CannotTell::because(sprintf('git hash-object --stdin gave no answer: The provided cwd "%s" does not exist.', $missing)));
});

it('leaves none of the files it hands git its input through', function (): void {
    $scratch = static function (): array {
        $found = glob(sprintf('%s/mutation-gate-git-*', sys_get_temp_dir()));

        return is_array($found) ? $found : [];
    };
    $before = $scratch();

    Command::in(Repository::empty()->root)->feed(['hash-object', '--stdin'], "hello\n");
    Command::in(Scratch::directory())->feed(['cat-file', '--batch'], "HEAD:./src/Money.php\n");

    expect($scratch())->toBe($before);
});
