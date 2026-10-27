<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Owner\CodeOwners;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Tests\Support\Schema;

/** @return list<string> */
function codeOwnersWarnings(CodeOwners $owners): array
{
    return array_map(static fn(Warning $warning): string => $warning->text(), [...$owners->warnings()]);
}

it('owns each path by the last rule of GitHub\'s own example that matches it', function (string $path, string $group): void {
    $owners = CodeOwners::read(Path::of('.github/CODEOWNERS'), (string) file_get_contents(Schema::at('tests/Fixtures/CodeOwners/CODEOWNERS')));

    expect($owners->groupOf(Path::of($path))->name())->toBe($group)
        ->and(codeOwnersWarnings($owners))->toBe([])
        ->and($owners->wasRead())->toBeTrue();
})->with([
    'the default owners' => ['README.md', '@global-owner1 @global-owner2'],
    'a file an inline comment follows the owner of' => ['web/app.js', '@js-owner'],
    'an email address' => ['cmd/main.go', 'docs@example.com'],
    'a team' => ['notes/todo.txt', '@octo-org/octocats'],
    'a later rule over an earlier one' => ['build/logs/today.log', '@octocat'],
    'a directory at the root' => ['docs/build-app/troubleshooting.md', '@doctocat'],
    'a directory at any depth' => ['src/apps/web/index.php', '@octocat'],
    'two owners' => ['scripts/deploy.sh', '@doctocat @octocat'],
    'a directory of logs deep down' => ['deeply/nested/logs/today.log', '@octocat'],
    'a subdirectory with its own owner' => ['apps/github/hook.rb', '@doctocat'],
    'the directory around it' => ['apps/web/index.php', '@octocat'],
]);

it('leaves a path unowned where its rule names no owner, or no rule matches it', function (): void {
    $owners = CodeOwners::read(Path::of('CODEOWNERS'), "/apps/ @octocat\n/apps/github\n");

    expect($owners->groupOf(Path::of('apps/github/hook.rb'))->isUnowned())->toBeTrue()
        ->and($owners->groupOf(Path::of('apps/web/index.php'))->name())->toBe('@octocat')
        ->and($owners->groupOf(Path::of('src/Money.php'))->isUnowned())->toBeTrue();
});

it('reads a rule whose owners an inline comment ends, and one with only a comment after it, as unowned', function (): void {
    $owners = CodeOwners::read(Path::of('CODEOWNERS'), "* @octocat\n/vendored/ # no owner\n*.js @js-owner #a comment @someone\n");

    expect($owners->groupOf(Path::of('vendored/lib.php'))->isUnowned())->toBeTrue()
        ->and($owners->groupOf(Path::of('web/app.js'))->name())->toBe('@js-owner');
});

it('reads a pattern that ends in a `#` as a path, and only one that starts with it as a comment', function (): void {
    $owners = CodeOwners::read(Path::of('CODEOWNERS'), "* @octocat\n/notes# @scribe\n");

    expect($owners->groupOf(Path::of('notes#'))->name())->toBe('@scribe')
        ->and($owners->groupOf(Path::of('notes'))->name())->toBe('@octocat');
});

it('reads spaces and tabs between a pattern and its owners, and a carriage return at the end of a line', function (): void {
    $owners = CodeOwners::read(Path::of('CODEOWNERS'), "  src/\t @jo \t@acme/payments\r\n");

    expect($owners->groupOf(Path::of('src/Money.php'))->name())->toBe('@jo @acme/payments');
});

it('skips each line GitHub\'s syntax refuses, with a warning that names it, and keeps the rest', function (): void {
    $owners = CodeOwners::read(Path::of('docs/CODEOWNERS'), implode("\n", [
        '* @octocat',
        '!vendor/ @jo',
        '[Bb]uild/ @jo',
        'build]/ @jo',
        '[build/ @jo',
        '\#notes @jo',
        'src/ octocat',
        '/ @jo',
        'lib/ @jo',
    ]));

    expect(codeOwnersWarnings($owners))->toBe([
        'docs/CODEOWNERS line 2 is skipped: a pattern that starts with `!` negates, which CODEOWNERS does not.',
        'docs/CODEOWNERS line 3 is skipped: CODEOWNERS has no `[ ]` ranges.',
        'docs/CODEOWNERS line 4 is skipped: CODEOWNERS has no `[ ]` ranges.',
        'docs/CODEOWNERS line 5 is skipped: CODEOWNERS has no `[ ]` ranges.',
        'docs/CODEOWNERS line 6 is skipped: a pattern that starts with `\#` is escaped, which CODEOWNERS does not read.',
        'docs/CODEOWNERS line 7 is skipped: `octocat` is no owner, which is `@user`, `@org/team` or an email address.',
        'docs/CODEOWNERS line 8 is skipped: the pattern names no path.',
    ])
        ->and($owners->groupOf(Path::of('vendor/lib.php'))->name())->toBe('@octocat')
        ->and($owners->groupOf(Path::of('src/Money.php'))->name())->toBe('@octocat')
        ->and($owners->groupOf(Path::of('lib/Money.php'))->name())->toBe('@jo');
});

it('names an owner it refuses with no character that could colour a terminal', function (): void {
    expect(codeOwnersWarnings(CodeOwners::read(Path::of('CODEOWNERS'), "src/ \e[31mred\n")))
        ->toBe(['CODEOWNERS line 1 is skipped: `[31mred` is no owner, which is `@user`, `@org/team` or an email address.']);
});

it('does not read a file over 3 MB, as GitHub does not', function (): void {
    $over = CodeOwners::read(Path::of('CODEOWNERS'), sprintf("* @octocat\n%s", str_repeat('#', 3 * 1024 * 1024 - 10)));
    $at = CodeOwners::read(Path::of('CODEOWNERS'), sprintf("* @octocat\n%s", str_repeat('#', 3 * 1024 * 1024 - 11)));

    expect($over->wasRead())->toBeFalse()
        ->and($over->groupOf(Path::of('src/Money.php'))->isUnowned())->toBeTrue()
        ->and(codeOwnersWarnings($over))->toBe(['CODEOWNERS is over 3 MB, which GitHub does not read, so no survivor has an owner.'])
        ->and($at->wasRead())->toBeTrue()
        ->and($at->groupOf(Path::of('src/Money.php'))->name())->toBe('@octocat');
});

it('reads an empty file as one that owns nothing', function (): void {
    $owners = CodeOwners::read(Path::of('CODEOWNERS'), '');

    expect($owners->wasRead())->toBeTrue()
        ->and($owners->groupOf(Path::of('src/Money.php'))->isUnowned())->toBeTrue();
});

it('has read nothing, and warns of nothing, with no file; and says why where one could not be read', function (): void {
    expect(CodeOwners::none()->wasRead())->toBeFalse()
        ->and(codeOwnersWarnings(CodeOwners::none()))->toBe([])
        ->and(CodeOwners::unread('git cannot read it.')->wasRead())->toBeFalse()
        ->and(codeOwnersWarnings(CodeOwners::unread('git cannot read it.')))->toBe(['git cannot read it.']);
});

it('looks for the file where GitHub does, in its order', function (): void {
    expect(CodeOwners::LOCATIONS)->toBe(['.github/CODEOWNERS', 'CODEOWNERS', 'docs/CODEOWNERS'])
        ->and(CodeOwners::MOST_BYTES)->toBe(3 * 1024 * 1024);
});
