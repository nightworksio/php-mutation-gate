<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitHub\RepositoryName;
use NightWorksIO\MutationGate\Core\Change\CannotTell;

it('takes a name as GITHUB_REPOSITORY writes it', function (): void {
    $name = RepositoryName::of('octo-org/gate.php');
    $dotted = RepositoryName::of('octo/.github');

    expect($name instanceof RepositoryName ? $name->value() : '')->toBe('octo-org/gate.php')
        ->and($dotted instanceof RepositoryName ? $dotted->value() : '')->toBe('octo/.github');
});

it('refuses anything that is not owner/name, so no name reaches another API path', function (string $name): void {
    expect(RepositoryName::of($name))->toEqual(CannotTell::because(sprintf('%s is no repository name of the form owner/name.', $name)));
})->with(['octo', 'octo/gate/issues', 'octo/../gate', '../gate', 'octo/..', 'octo/.', '', 'octo/gate?x=1', 'octo_org/gate']);

it('reads the repository a remote names on GitHub, over HTTPS or SSH', function (string $url): void {
    $name = RepositoryName::fromRemote($url, 'github.com');

    expect($name instanceof RepositoryName ? $name->value() : '')->toBe('octo/gate');
})->with([
    'https://github.com/octo/gate.git',
    'https://github.com/octo/gate',
    'https://github.com/octo/gate/',
    'git@github.com:octo/gate.git',
    'ssh://git@github.com/octo/gate.git',
]);

it('reads a remote on an Enterprise server by its host', function (): void {
    $name = RepositoryName::fromRemote('git@git.example.com:octo/gate.git', 'git.example.com');

    expect($name instanceof RepositoryName ? $name->value() : '')->toBe('octo/gate');
});

it('says a remote on another host, or naming no repository, is no repository on GitHub', function (): void {
    expect(RepositoryName::fromRemote('git@github.com:octo/...git', 'github.com'))->toBeInstanceOf(CannotTell::class)
        ->and(RepositoryName::fromRemote('https://github.com/octo/..', 'github.com'))->toBeInstanceOf(CannotTell::class)
        ->and(RepositoryName::fromRemote('git@gitlab.com:octo/gate.git', 'github.com'))
        ->toEqual(CannotTell::because('git\'s origin remote, git@gitlab.com:octo/gate.git, is not a repository on github.com.'));
});
