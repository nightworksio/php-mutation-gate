<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Git\Git;
use NightWorksIO\MutationGate\Adapter\GitHub\PassedPullRequests;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\Detached;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Port\Repository;
use NightWorksIO\MutationGate\Tests\Fakes\RepositoryFake;
use NightWorksIO\MutationGate\Tests\Support\Repository as Fixture;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

// What every repository answers for a checkout of the fixture, first on main
// with origin/HEAD pointing at main, then on a detached HEAD with no remote.
// One line per implementation.

afterEach(function (): void {
    Scratch::sweep();
});

const GITHUB_RUN = [
    'GITHUB_REPOSITORY' => 'octo/gate',
    'GITHUB_SHA' => 'head',
];

$github = static fn(): MockHttpClient => new MockHttpClient(
    static fn(): MockResponse => new MockResponse('{"message": "Not Found"}', ['http_code' => 404]),
);
$onMain = static function (): Fixture {
    $fixture = Fixture::ofTheFixture();
    $fixture->git('update-ref', 'refs/remotes/origin/main', 'HEAD');
    $fixture->git('symbolic-ref', 'refs/remotes/origin/HEAD', 'refs/remotes/origin/main');

    return $fixture;
};
$detached = static function (): Fixture {
    $fixture = Fixture::ofTheFixture();
    $fixture->git('checkout', '--quiet', '--detach', 'HEAD');

    return $fixture;
};

it('names the commit the checkout is at, the branch it is on and the default branch', function (
    Repository $repository,
): void {
    $head = $repository->head();

    expect($head instanceof Revision ? preg_match('/^[0-9a-f]{40}$/', $head->name()) : $head)->toBe(1)
        ->and($repository->branch())->toEqual(Scope::branch('main'))
        ->and($repository->defaultBranch())->toEqual(Scope::branch('main'));
})->with([
    'the fake' => fn(): Repository => RepositoryFake::onMain(Revision::ref(str_repeat('5e', 20))),
    'git' => fn(): Repository => Git::at($onMain()->root),
    'git, through GitHub' => fn(): Repository => PassedPullRequests::over(
        Git::at($onMain()->root),
        $github(),
        GITHUB_RUN,
        'mutation / verdict',
    ),
]);

it('says a detached HEAD is on no branch, and cannot tell a default branch no remote names', function (
    Repository $repository,
): void {
    expect($repository->branch())->toEqual(Detached::head())
        ->and($repository->defaultBranch())->toEqual(CannotTell::because(
            'refs/remotes/origin/HEAD points at no branch, so git cannot name the default branch.',
        ))
        ->and($repository->head())->toBeInstanceOf(Revision::class);
})->with([
    'the fake' => fn(): Repository => RepositoryFake::detachedAt(Revision::ref(str_repeat('5e', 20))),
    'git' => fn(): Repository => Git::at($detached()->root),
    'git, through GitHub' => fn(): Repository => PassedPullRequests::over(
        Git::at($detached()->root),
        $github(),
        GITHUB_RUN,
        'mutation / verdict',
    ),
]);

$committed = static fn(): Fixture => Fixture::ofTheFixture()
    ->write('.gitignore', "/build/\n")
    ->commit('Everything on disk.')
    ->write('build/cache.txt', 'ignored');

it('says a working tree that holds nothing but its commit and what git ignores is clean', function (
    Repository $repository,
): void {
    expect($repository->isClean())->toBeTrue();
})->with([
    'the fake' => fn(): Repository => RepositoryFake::onMain(Revision::ref(str_repeat('5e', 20))),
    'git' => fn(): Repository => Git::at($committed()->root),
    'git, through GitHub' => fn(): Repository => PassedPullRequests::over(
        Git::at($committed()->root),
        $github(),
        GITHUB_RUN,
        'mutation / verdict',
    ),
]);

it('says a working tree with a change, staged or not, or a file git neither tracks nor ignores, is not clean', function (
    Repository $repository,
): void {
    expect($repository->isClean())->toBeFalse();
})->with([
    'the fake' => fn(): Repository => RepositoryFake::onMain(Revision::ref(str_repeat('5e', 20)))->changed(),
    'a change git does not track yet' => fn(): Repository => Git::at(
        $committed()->write('src/Money.php', "<?php\nreturn 3;\n")->root,
    ),
    'a staged change' => function () use ($committed): Repository {
        $fixture = $committed()->write('src/Money.php', "<?php\nreturn 3;\n");
        $fixture->git('add', 'src/Money.php');

        return Git::at($fixture->root);
    },
    'an untracked file' => fn(): Repository => Git::at($committed()->write('src/Rate.php', "<?php\n")->root),
    'a change to a file marked assume-unchanged' => function () use ($committed): Repository {
        $fixture = $committed();
        $fixture->git('update-index', '--assume-unchanged', 'src/Money.php');
        $fixture->write('src/Money.php', "<?php\nreturn 3;\n");

        return Git::at($fixture->root);
    },
    'a change to a file marked skip-worktree' => function () use ($committed): Repository {
        $fixture = $committed();
        $fixture->git('update-index', '--skip-worktree', 'src/Money.php');
        $fixture->write('src/Money.php', "<?php\nreturn 3;\n");

        return Git::at($fixture->root);
    },
    'git, through GitHub' => fn(): Repository => PassedPullRequests::over(
        Git::at(Fixture::ofTheFixture()->root),
        $github(),
        GITHUB_RUN,
        'mutation / verdict',
    ),
]);

it('cannot tell where a directory that is no repository stands', function (): void {
    $git = Git::at(Scratch::directory());

    expect($git->head())->toBeInstanceOf(CannotTell::class)
        ->and($git->isClean())->toBeInstanceOf(CannotTell::class)
        ->and($git->branch())->toBeInstanceOf(CannotTell::class)
        ->and($git->defaultBranch())->toBeInstanceOf(CannotTell::class);
});
