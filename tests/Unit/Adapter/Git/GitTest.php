<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Git\Git;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Tests\Support\Environment;
use NightWorksIO\MutationGate\Tests\Support\FileTexts;
use NightWorksIO\MutationGate\Tests\Support\Repository;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * Each change, by its path: its kind, the lines it gained, and the path it came from.
 *
 * @return array<string, array{string, list<int>, string}>
 */
function changesByPath(Changes|CannotTell $changes): array
{
    $said = [];

    foreach ($changes instanceof Changes ? $changes : [] as $change) {
        $said[$change->path()->value()] = [
            $change->kind()->value,
            array_map(static fn(Line $line): int => $line->number(), iterator_to_array($change->lines(), preserve_keys: false)),
            $change->previousPath()->value(),
        ];
    }

    return $said;
}

it('says what changed from the base to what is on disk, committed or not, tracked or not', function (): void {
    $repository = Repository::empty()
        ->write('.gitignore', "ignored.php\n")
        ->write('src/A.php', "<?php\none\ntwo\nthree\n")
        ->write('src/B.php', sprintf("<?php\n%s\n", str_repeat('b', 60)))
        ->write('src/C.php', "<?php\nc\nc\nc\nc\n")
        ->write('src/E.php', "<?php\ne1\ne2\ne3\ne4\ne5\ne6\n")
        ->write('src/J.php', "<?php\nkeep\ndrop\n")
        ->write('src/say "hi".php', "<?php\n")
        ->write('src/two words.php', "<?php\n")
        ->commit('The base.');
    $repository->git('tag', 'base');
    $repository->write('src/A.php', "<?php\none\nTWO\nthree\nfour\n")->commit('After the base.');
    $repository->git('rm', '--quiet', 'src/B.php');
    $repository->git('mv', 'src/C.php', 'src/D.php');
    $repository->git('mv', 'src/E.php', 'src/F.php');
    $repository->write('src/F.php', "<?php\ne1\ne2\nE3\ne4\ne5\ne6\n");
    $repository->write('src/G.php', sprintf("<?php\n%s\n", str_repeat('g', 60)));
    $repository->git('add', 'src/G.php');
    $repository->write('src/J.php', "<?php\nkeep\n");
    $repository->write('src/say "hi".php', "<?php\n// hi\n");
    $repository->write('src/two words.php', "<?php\n// two\n");
    $repository->write('src/H.php', "<?php\nh\nh");
    $repository->write('src/I.php', '');
    $repository->write('ignored.php', "<?php\n");

    expect(changesByPath(Git::at($repository->root)->changesSince(Revision::ref('base'))))->toEqual([
        'src/A.php' => ['modified', [3, 5], 'src/A.php'],
        'src/B.php' => ['deleted', [], 'src/B.php'],
        'src/D.php' => ['renamed', [], 'src/C.php'],
        'src/F.php' => ['renamed', [4], 'src/E.php'],
        'src/G.php' => ['added', [1, 2], 'src/G.php'],
        'src/J.php' => ['modified', [], 'src/J.php'],
        'src/say "hi".php' => ['modified', [2], 'src/say "hi".php'],
        'src/two words.php' => ['modified', [2], 'src/two words.php'],
        'src/H.php' => ['added', [1, 2, 3], 'src/H.php'],
        'src/I.php' => ['added', [], 'src/I.php'],
    ]);
});

it('says what changed since where a branch left its base, not since the base\'s newer commits', function (): void {
    $repository = Repository::empty()->write('src/Main.php', "<?php\n")->write('src/Feature.php', "<?php\n")->commit('The base.');
    $repository->git('checkout', '--quiet', '-b', 'feature');
    $repository->write('src/Feature.php', "<?php\nfeature\n")->commit('The feature.');
    $repository->git('checkout', '--quiet', 'main');
    $repository->write('src/Main.php', "<?php\nmain\n")->commit('Main moves on.');
    $repository->git('checkout', '--quiet', 'feature');

    expect(changesByPath(Git::at($repository->root)->changesSince(Revision::ref('main'))))->toEqual([
        'src/Feature.php' => ['modified', [2], 'src/Feature.php'],
    ]);
});

it('says what changed inside the directory it is at, spelt from there', function (): void {
    $repository = Repository::empty()->write('packages/money/src/A.php', "<?php\n")->write('src/Other.php', "<?php\n")->commit('The base.');
    $repository->git('tag', 'base');
    $repository->write('packages/money/src/A.php', "<?php\na\n")->write('src/Other.php', "<?php\nother\n");
    $repository->write('packages/money/src/New.php', "<?php\n")->write('src/New.php', "<?php\n");

    expect(changesByPath(Git::at(sprintf('%s/packages/money', $repository->root))->changesSince(Revision::ref('base'))))->toEqual([
        'src/A.php' => ['modified', [2], 'src/A.php'],
        'src/New.php' => ['added', [1], 'src/New.php'],
    ]);
});

it('reads the lines a change gained with no external diff, whatever the repository configures', function (): void {
    $repository = Repository::empty()->write('src/A.php', "<?php\n")->commit('The base.');
    $repository->git('tag', 'base');
    $repository->git('config', 'diff.external', 'true');
    $repository->write('src/A.php', "<?php\na\n");

    expect(changesByPath(Git::at($repository->root)->changesSince(Revision::ref('base'))))->toEqual([
        'src/A.php' => ['modified', [2], 'src/A.php'],
    ]);
});

it('follows a rename whatever the repository configures', function (): void {
    $repository = Repository::empty()->write('src/E.php', "<?php\ne1\ne2\ne3\ne4\ne5\ne6\n")->commit('The base.');
    $repository->git('tag', 'base');
    $repository->git('config', 'diff.renames', 'false');
    $repository->git('mv', 'src/E.php', 'src/F.php');
    $repository->write('src/F.php', "<?php\ne1\ne2\nE3\ne4\ne5\ne6\n");

    expect(changesByPath(Git::at($repository->root)->changesSince(Revision::ref('base'))))->toEqual([
        'src/F.php' => ['renamed', [4], 'src/E.php'],
    ]);
});

it('reads an untracked link as the path it points to, as git diffs it, and never what it points to', function (): void {
    $repository = Repository::empty()->write('src/A.php', "<?php\n")->commit('The base.');
    $repository->git('tag', 'base');
    $outside = sprintf('%s/secret.txt', Scratch::directory());
    file_put_contents($outside, "one\ntwo\nthree\n");
    symlink('nowhere', sprintf('%s/src/Dangling.php', $repository->root));
    symlink($outside, sprintf('%s/src/Out.php', $repository->root));
    $git = Git::at($repository->root);

    expect(changesByPath($git->changesSince(Revision::ref('base'))))->toEqual([
        'src/Dangling.php' => ['added', [1], 'src/Dangling.php'],
        'src/Out.php' => ['added', [1], 'src/Out.php'],
    ])->and($git->fileAt(Path::of('src/Out.php'), Revision::workingTree()))->toEqual(Contents::of($outside));
});


it('hashes a link as git stores it, from the path it points to', function (): void {
    $repository = Repository::empty()->write('src/A.php', "<?php\n")->commit('The base.');
    $outside = sprintf('%s/secret.txt', Scratch::directory());
    file_put_contents($outside, "secret\n");
    symlink($outside, sprintf('%s/src/Out.php', $repository->root));
    $repository->git('add', 'src/Out.php');
    $stored = trim(explode(' ', $repository->git('ls-files', '-s', 'src/Out.php'))[1]);

    $fingerprints = Git::at($repository->root)->fingerprints();

    expect($fingerprints instanceof Fingerprints ? $fingerprints->digestOf(Path::of('src/Out.php')) : $fingerprints)
        ->toEqual(Digest::of($stored));
});

it('cannot tell what changed since a revision it does not have, or one spelt as an option', function (string $revision): void {
    $repository = Repository::empty()->write('src/A.php', "<?php\n")->commit('The base.');
    $said = Git::at($repository->root)->changesSince(Revision::ref($revision));

    expect($said)->toBeInstanceOf(CannotTell::class)
        ->and($said instanceof CannotTell ? $said->why() : '')->toStartWith(sprintf('git merge-base --end-of-options %s HEAD gave no answer: fatal:', $revision));
})->with(['no-such-revision', '--independent']);

it('cannot tell what changed where git cannot read what the base held', function (): void {
    $repository = Repository::empty()->write('src/A.php', "one\n")->commit('The base.');
    $repository->git('tag', 'base');
    $blob = trim($repository->git('rev-parse', 'base:src/A.php'));
    unlink(sprintf('%s/.git/objects/%s/%s', $repository->root, mb_substr($blob, 0, 2), mb_substr($blob, 2)));
    $repository->write('src/A.php', "two\n");

    $said = Git::at($repository->root)->changesSince(Revision::ref('base'));

    expect($said)->toBeInstanceOf(CannotTell::class)
        ->and($said instanceof CannotTell ? $said->why() : '')->toStartWith('git diff --find-renames --relative --name-status -z ');
});

it('keeps what a run withholds from git itself', function (): void {
    $repository = Repository::empty();
    $elsewhere = ['GIT_DIR' => sprintf('%s/not-a-repository', Scratch::directory())];
    $plain = Environment::during($elsewhere, static fn(): Git => Git::at($repository->root));
    $withholding = Environment::during(
        $elsewhere,
        static fn(): Git => Git::withholding($repository->root, Withheld::of('GIT_DIR')),
    );

    expect($plain->fingerprints())->toBeInstanceOf(CannotTell::class)
        ->and($withholding->fingerprints())->toEqual(Fingerprints::none());
});

it('cannot tell anything outside a repository', function (): void {
    $directory = Scratch::directory();

    expect(Git::at($directory)->changesSince(Revision::ref('HEAD')))->toBeInstanceOf(CannotTell::class)
        ->and(Git::at($directory)->fingerprints())->toBeInstanceOf(CannotTell::class);
});

it('fingerprints every file on disk that git does not ignore by the blob id of what it holds there', function (): void {
    $repository = Repository::empty()
        ->write('.gitignore', "ignored.txt\n")
        ->write('.gitattributes', "*.txt text\n")
        ->write('hello.txt', "hello\n")
        ->write('gone.txt', "gone\n")
        ->write('src/Money.php', "<?php\n")
        ->commit('The base.');
    $repository->write('src/Money.php', "<?php\nreturn 2;\n")->write('crlf.txt', "one\r\ntwo\r\n")->write('ignored.txt', 'ignored');
    unlink(sprintf('%s/gone.txt', $repository->root));

    $fingerprints = Git::at($repository->root)->fingerprints();
    $blob = static fn(string $path): Digest => Digest::of(trim($repository->git('hash-object', '--no-filters', $path)));

    expect($fingerprints)->toBeInstanceOf(Fingerprints::class)
        ->and($fingerprints instanceof Fingerprints ? $fingerprints->count() : 0)->toBe(5)
        ->and($fingerprints instanceof Fingerprints ? $fingerprints->digestOf(Path::of('hello.txt')) : null)
        ->toEqual(Digest::of('ce013625030ba8dba906f756967f9e9ca394464a'))
        ->and($fingerprints instanceof Fingerprints ? $fingerprints->digestOf(Path::of('src/Money.php')) : null)->toEqual($blob('src/Money.php'))
        ->and($fingerprints instanceof Fingerprints ? $fingerprints->digestOf(Path::of('crlf.txt')) : null)->toEqual($blob('crlf.txt'))
        ->and($fingerprints instanceof Fingerprints ? $fingerprints->digestOf(Path::of('.gitignore')) : null)->toEqual($blob('.gitignore'))
        ->and($fingerprints instanceof Fingerprints ? $fingerprints->digestOf(Path::of('gone.txt')) : null)->toEqual(Missing::at(Path::of('gone.txt')))
        ->and($fingerprints instanceof Fingerprints ? $fingerprints->digestOf(Path::of('ignored.txt')) : null)->toEqual(Missing::at(Path::of('ignored.txt')));
});

it('fingerprints nothing in a repository with no file', function (): void {
    expect(Git::at(Repository::empty()->root)->fingerprints())->toEqual(Fingerprints::none());
});

it('cannot tell the fingerprints of a file whose name holds a line break', function (): void {
    $repository = Repository::empty()->write("one\ntwo.txt", 'text');

    expect(Git::at($repository->root)->fingerprints())
        ->toEqual(CannotTell::because("git cannot hash \"one\ntwo.txt\" with the others, because its name holds a line break."));
});

it('cannot tell the fingerprints where git cannot read a file', function (): void {
    $repository = Repository::empty()->write('secret.txt', 'secret');
    chmod(sprintf('%s/secret.txt', $repository->root), 0o000);

    $said = Git::at($repository->root)->fingerprints();
    chmod(sprintf('%s/secret.txt', $repository->root), 0o644);

    expect($said)->toBeInstanceOf(CannotTell::class)
        ->and($said instanceof CannotTell ? $said->why() : '')->toStartWith('git hash-object --no-filters --stdin-paths gave no answer: ');
});

it('reads a file as it was at a revision, and as it is on disk', function (): void {
    $repository = Repository::ofTheFixture();
    $git = Git::at($repository->root);

    expect($git->fileAt(Path::of('src/Money.php'), Revision::ref('fixture-base')))->toEqual(Contents::of("<?php\nreturn 1;\n"))
        ->and($git->fileAt(Path::of('src/Money.php'), Revision::workingTree()))->toEqual(Contents::of("<?php\nreturn 2;\n"))
        ->and($git->fileAt(Path::of('src/Limit.php'), Revision::ref('fixture-base')))->toEqual(Missing::at(Path::of('src/Limit.php')))
        ->and($git->fileAt(Path::of('src/Gone.php'), Revision::workingTree()))->toEqual(Missing::at(Path::of('src/Gone.php')))
        ->and($git->fileAt(Path::of('src'), Revision::workingTree()))->toEqual(Missing::at(Path::of('src')));
});

it('reads a file at a revision spelt from the directory it is at', function (): void {
    $repository = Repository::empty()->write('packages/money/src/A.php', "<?php\na\n")->commit('The base.');

    expect(Git::at(sprintf('%s/packages/money', $repository->root))->fileAt(Path::of('src/A.php'), Revision::ref('HEAD')))
        ->toEqual(Contents::of("<?php\na\n"));
});

it('cannot tell what a file held at a revision the repository does not have', function (): void {
    expect(Git::at(Repository::ofTheFixture()->root)->fileAt(Path::of('src/Money.php'), Revision::ref('no-such-revision')))
        ->toEqual(CannotTell::because('no-such-revision is not a revision this repository has.'));
});

it('reads every file at a revision at the commit it named when first read', function (): void {
    $repository = Repository::empty()->write('src/A.php', "<?php\na\n")->write('src/B.php', "<?php\nb\n")->commit('The base.');
    $repository->git('branch', 'moving');
    $git = Git::at($repository->root);
    $first = $git->fileAt(Path::of('src/A.php'), Revision::ref('moving'));

    $repository->write('src/B.php', "<?php\nmoved\n")->commit('Moved on.');
    $repository->git('branch', '--force', 'moving', 'HEAD');

    expect($first)->toEqual(Contents::of("<?php\na\n"))
        ->and($git->fileAt(Path::of('src/B.php'), Revision::ref('moving')))->toEqual(Contents::of("<?php\nb\n"))
        ->and(Git::at($repository->root)->fileAt(Path::of('src/B.php'), Revision::ref('moving')))->toEqual(Contents::of("<?php\nmoved\n"))
        ->and($git->fileAt(Path::of('src/B.php'), Revision::ref('no-such-revision')))->toEqual(CannotTell::because('no-such-revision is not a revision this repository has.'))
        ->and($git->fileAt(Path::of('src/A.php'), Revision::ref('no-such-revision')))->toEqual(CannotTell::because('no-such-revision is not a revision this repository has.'));
});

it('reads many files at a revision at once, each as it was, and one that was not there as missing', function (): void {
    $repository = Repository::empty()
        ->write('src/A.php', "<?php\na\n")
        ->write('src/Euro €.php', "€ \0\n")
        ->write('src/Empty.php', '')
        ->write("src/Line\nBreak.php", "<?php\nbroken\n")
        ->write("src/Carriage\r.php", "<?php\ncarried\n")
        ->commit('The base.');
    $repository->write('src/A.php', "<?php\nchanged\n");

    $files = Git::at($repository->root)->filesAt(
        Paths::of(
            Path::of('src/A.php'),
            Path::of('src/Euro €.php'),
            Path::of('src/Empty.php'),
            Path::of("src/Line\nBreak.php"),
            Path::of("src/Carriage\r.php"),
            Path::of("src/Gone\n.php"),
            Path::of('src/Gone.php'),
            Path::of('src'),
        ),
        Revision::ref('HEAD'),
    );

    expect($files instanceof ByPath ? FileTexts::of($files) : $files)->toBe([
        'src/A.php' => "<?php\na\n",
        'src/Euro €.php' => "€ \0\n",
        'src/Empty.php' => '',
        "src/Line\nBreak.php" => "<?php\nbroken\n",
        "src/Carriage\r.php" => "<?php\ncarried\n",
        "src/Gone\n.php" => null,
        'src/Gone.php' => null,
        'src' => null,
    ]);
});

it('reads a file named with a line feed as missing where the commit holds no file at it, and cannot tell it where git lost it', function (): void {
    $repository = Repository::empty()
        ->write("src/Line\nBreak.php", "<?php\nbroken\n")
        ->write("src/Folder\n/A.php", "<?php\na\n")
        ->commit('The base.');
    $blob = trim($repository->git('rev-parse', "HEAD:src/Line\nBreak.php"));
    $git = Git::at($repository->root);
    $folder = $git->filesAt(Paths::of(Path::of("src/Folder\n")), Revision::ref('HEAD'));
    unlink(sprintf('%s/.git/objects/%s/%s', $repository->root, mb_substr($blob, 0, 2), mb_substr($blob, 2)));

    expect($folder instanceof ByPath ? FileTexts::of($folder) : $folder)->toBe(["src/Folder\n" => null])
        ->and($git->fileAt(Path::of("src/Line\nBreak.php"), Revision::ref('HEAD')))->toBeInstanceOf(CannotTell::class)
        ->and($git->filesAt(Paths::of(Path::of('src/Other.php'), Path::of("src/Line\nBreak.php")), Revision::ref('HEAD')))
        ->toBeInstanceOf(CannotTell::class);
});

it('reads many files as they are on disk, and many files at a revision spelt from the directory it is at', function (): void {
    $repository = Repository::empty()->write('packages/money/src/A.php', "<?php\na\n")->commit('The base.');
    $repository->write('packages/money/src/A.php', "<?php\nnow\n");
    $git = Git::at(sprintf('%s/packages/money', $repository->root));
    $paths = Paths::of(Path::of('src/A.php'), Path::of('src/B.php'));
    $then = $git->filesAt($paths, Revision::ref('HEAD'));
    $now = $git->filesAt($paths, Revision::workingTree());

    expect($then instanceof ByPath ? FileTexts::of($then) : $then)->toBe(['src/A.php' => "<?php\na\n", 'src/B.php' => null])
        ->and($now instanceof ByPath ? FileTexts::of($now) : $now)->toBe(['src/A.php' => "<?php\nnow\n", 'src/B.php' => null])
        ->and($git->filesAt(Paths::none(), Revision::ref('HEAD')))->toEqual(ByPath::mapping(Paths::none(), Missing::at(...)));
});

it('cannot tell the files at a revision the repository does not have, or where git cannot read them', function (): void {
    $repository = Repository::empty()->write('src/A.php', "<?php\na\n")->commit('The base.');
    $git = Git::at($repository->root);
    $git->fileAt(Path::of('src/A.php'), Revision::ref('HEAD'));
    rename(sprintf('%s/.git', $repository->root), sprintf('%s/.gone', $repository->root));

    $unread = $git->filesAt(Paths::of(Path::of('src/A.php')), Revision::ref('HEAD'));

    expect($git->filesAt(Paths::of(Path::of('src/A.php')), Revision::ref('no-such-revision')))
        ->toEqual(CannotTell::because('no-such-revision is not a revision this repository has.'))
        ->and($unread)->toBeInstanceOf(CannotTell::class)
        ->and($unread instanceof CannotTell ? $unread->why() : '')->toStartWith('git cat-file --batch gave no answer: ');
});

it('refuses a revision named like an option, which git would read as one', function (string $name): void {
    $repository = Repository::empty()->write('src/A.php', "<?php\n")->commit('The base.');
    $written = sprintf('%s/written-by-git', $repository->root);

    expect(Git::at($repository->root)->fileAt(Path::of('src/A.php'), Revision::ref(sprintf($name, $written))))
        ->toEqual(CannotTell::because(sprintf('%s is not a revision this repository has.', sprintf($name, $written))))
        ->and(file_exists($written))->toBeFalse();
})->with(['--output=%s', '-h%s']);

it('reads the changes alike whatever copy detection the user\'s own config asks for', function (): void {
    $repository = Repository::empty()->write('src/A.php', "<?php\n\nfinal class A\n{\n    public function one(): int { return 1; }\n}\n")->commit('The base.');
    $repository->git('tag', 'base');
    $repository->git('config', 'diff.renames', 'copies');
    $repository->write('src/Copy.php', "<?php\n\nfinal class A\n{\n    public function one(): int { return 1; }\n}\n")->commit('A copy.');

    expect(changesByPath(Git::at($repository->root)->changesSince(Revision::ref('base'))))->toEqual([
        'src/Copy.php' => ['added', [1, 2, 3, 4, 5, 6], 'src/Copy.php'],
    ]);
});
