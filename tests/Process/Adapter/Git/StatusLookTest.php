<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Git\Git;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Looked;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprint;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Tests\Support\Repository;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

$holds = ['holds:src/Adapter/Git/StatusLook.php', 'holds:src/Core/Change/Looked.php', 'holds:src/Adapter/Git/Git.php'];

/** Each file's digest, by its path, in the order of the paths. */
$digests = static function (Fingerprints|CannotTell $files): array {
    $digests = [];

    foreach ($files instanceof Fingerprints ? $files : [] as $file) {
        $digests[$file->path()->value()] = $file->digest()->value();
    }

    ksort($digests);

    return $digests;
};

/** A look after this one, or why there is none. */
$after = static fn(Git $git, Looked|CannotTell $then): Looked|CannotTell => $then instanceof Looked
    ? $git->lookedSince($then)
    : $then;

it('sees through git status what hashing every file sees, edit after edit, at one commit and after another', function () use ($digests, $after): void {
    $repository = Repository::empty()->write('src/A.php', "<?php\n")->write('src/B.php', "<?php\n// b\n")
        ->write('src/C.php', "<?php\n// c\n")->write('.gitignore', "/var/\n")->commit('The base.');
    $git = Git::at($repository->root);
    $edits = [
        'nothing changed' => static fn(): null => null,
        'a tracked file edited' => static fn(): Repository => $repository->write('src/A.php', "<?php\n// edited\n"),
        'the same file edited again' => static fn(): Repository => $repository->write('src/A.php', "<?php\n// again\n"),
        'a file added and an ignored one' => static fn(): Repository => $repository->write('src/D.php', "<?php\n// new name with space\n")->write('var/x.php', "<?php\n"),
        'a file deleted' => static fn(): bool => unlink(sprintf('%s/src/B.php', $repository->root)),
        'a file renamed and staged' => static fn(): string => $repository->git('mv', 'src/C.php', 'src/E F.php'),
        'an edit put back' => static fn(): string => $repository->git('checkout', '--', 'src/A.php'),
        'a commit' => static fn(): Repository => $repository->commit('The next.'),
        'an edit after the commit' => static fn(): Repository => $repository->write('src/E F.php', "<?php\n// moved\n"),
    ];
    $look = $git->lookedSince(Looked::none());
    $seen = [];

    foreach ($edits as $edit => $made) {
        $made();
        $look = $after($git, $look);
        $seen[$edit] = $digests($look instanceof Looked ? $look->files() : $look) === $digests($git->fingerprints());
    }

    expect($seen)->toBe(array_fill_keys(array_keys($edits), value: true));
})->group(...$holds);

it('hashes again only what differs from the commit, or differed at the last look', function () use ($digests): void {
    $repository = Repository::empty()->write('src/A.php', "<?php\n")->write('src/B.php', "<?php\n// b\n")->commit('The base.');
    $git = Git::at($repository->root);
    $first = $git->lookedSince(Looked::none());
    $planted = Looked::at(
        Fingerprints::of(...[...$first instanceof Looked ? $first->files() : []])->with(Fingerprint::of(
            Path::of('src/B.php'),
            Digest::of('kept'),
        )),
        Revision::ref(trim($repository->git('rev-parse', 'HEAD'))),
        Paths::none(),
    );
    $repository->write('src/A.php', "<?php\n// edited\n");

    $look = $git->lookedSince($planted);

    expect($digests($look instanceof Looked ? $look->files() : $look)['src/B.php'])->toBe('kept')
        ->and($look instanceof Looked ? $look->differing() : Paths::none())->toEqual(Paths::of(Path::of('src/A.php')));
})->group(...$holds);

it('cannot tell anything outside a repository', function (): void {
    expect(Git::at(Scratch::directory())->lookedSince(Looked::none()))->toBeInstanceOf(CannotTell::class);
})->group(...$holds);

it('cannot tell the files where git cannot hash one that differs at the same commit', function (): void {
    $repository = Repository::empty()->write('src/A.php', "<?php\n")->commit('The base.');
    $git = Git::at($repository->root);
    $first = $git->lookedSince(Looked::none());
    $repository->write('src/A.php', "<?php\n// edited\n");
    chmod(sprintf('%s/src/A.php', $repository->root), 0o000);

    $look = $first instanceof Looked ? $git->lookedSince($first) : $first;
    chmod(sprintf('%s/src/A.php', $repository->root), 0o644);

    expect($first)->toBeInstanceOf(Looked::class)
        ->and($look)->toBeInstanceOf(CannotTell::class);
})->group(...$holds);

it('sees a file in a directory git does not track, a conflicted file, a file named as a number and none of the gate\'s workspace', function () use ($digests, $after): void {
    $repository = Repository::empty()->write('src/A.php', "<?php\n")->write('7', "seven\n")->commit('The base.');
    $repository->git('checkout', '-q', '-b', 'other');
    $repository->write('src/A.php', "<?php\n// other\n")->commit('The other side.');
    $repository->git('checkout', '-q', '-');
    $repository->write('src/A.php', "<?php\n// this\n")->commit('This side.');
    $git = Git::at($repository->root);
    $look = $git->lookedSince(Looked::none());
    $repository->attempted('merge', 'other');
    $repository->write('7', "eight\n")->write('lib/new/X.php', "<?php\n")->write('.mutation-gate/kept.json', "{}\n");

    $look = $after($git, $look);

    expect($digests($look instanceof Looked ? $look->files() : $look))->toBe($digests($git->fingerprints()))
        ->and($look instanceof Looked ? [...$look->differing()] : [])->not->toContain(Path::of('.mutation-gate/kept.json'));
})->group(...$holds);

it('takes no lock on the index to look, so a look beside another git command never fails it', function (): void {
    $repository = Repository::empty()->write('src/A.php', "<?php\n")->commit('The base.');
    $git = Git::at($repository->root);
    $look = $git->lookedSince(Looked::none());
    $index = sprintf('%s/.git/index', $repository->root);
    touch(sprintf('%s/src/A.php', $repository->root), 2_000_000_000);
    touch($index, 1_000_000_000);

    $again = $look instanceof Looked ? $git->lookedSince($look) : $look;
    clearstatcache();

    expect($again)->toBeInstanceOf(Looked::class)
        ->and(filemtime($index))->toBe(1_000_000_000);
})->group(...$holds);
