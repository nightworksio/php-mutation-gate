<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\CodeOwnersFile;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\PullRequestNumber;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Owner\CodeOwners;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Planned;

/** @param array<string, array<string, string>> $files what each file holds, by revision name and path */
function codeOwnersSource(array $files): ChangeSourceFake
{
    return new ChangeSourceFake(Revision::ref('base'), Changes::none(), $files);
}

function pullRequestPlan(): Plan
{
    $run = RunOn::pullRequest(PullRequestNumber::of(7), Scope::branch('main'), '');

    return $run instanceof RunOn ? Planned::twoShards()->on($run) : Planned::twoShards();
}

function ownerOf(CodeOwners $owners, string $path): string
{
    return $owners->groupOf(Path::of($path))->name();
}

it('reads the file at the commit the plan was made on, off a pull request', function (): void {
    $owners = CodeOwnersFile::of(codeOwnersSource([
        Flows::HEAD => ['CODEOWNERS' => '* @at-the-commit'],
        Revision::workingTree()->name() => ['CODEOWNERS' => '* @on-disk'],
    ]), Planned::twoShards());

    expect(ownerOf($owners, 'src/Money.php'))->toBe('@at-the-commit');
});

it('reads the file on a pull request at the default branch as the checkout fetched it, which the pull request cannot edit', function (): void {
    $owners = CodeOwnersFile::of(codeOwnersSource([
        'refs/remotes/origin/main' => ['CODEOWNERS' => '* @on-the-base'],
        Flows::HEAD => ['CODEOWNERS' => '* @the-pull-request'],
    ]), pullRequestPlan());

    expect(ownerOf($owners, 'src/Money.php'))->toBe('@on-the-base');
});

it('reads the file on a pull request at the branch it merges into, where its CI names one', function (): void {
    $run = RunOn::pullRequest(PullRequestNumber::of(7), Scope::branch('main'), 'release/2.x');
    $owners = CodeOwnersFile::of(codeOwnersSource([
        'refs/remotes/origin/main' => ['CODEOWNERS' => '* @on-main'],
        'refs/remotes/origin/release/2.x' => ['CODEOWNERS' => '* @on-the-release'],
    ]), $run instanceof RunOn ? Planned::twoShards()->on($run) : Planned::twoShards());

    expect(ownerOf($owners, 'src/Money.php'))->toBe('@on-the-release');
});

it('reads the first file of GitHub\'s three places that holds one, an empty one among them', function (ChangeSourceFake $source, string $owner, bool $read): void {
    $owners = CodeOwnersFile::of($source, Planned::twoShards());

    expect(ownerOf($owners, 'src/Money.php'))->toBe($owner)
        ->and($owners->wasRead())->toBe($read);
})->with([
    'under .github first' => [fn(): ChangeSourceFake => codeOwnersSource([Flows::HEAD => ['.github/CODEOWNERS' => '* @github', 'CODEOWNERS' => '* @root', 'docs/CODEOWNERS' => '* @docs']]), '@github', true],
    'at the root next' => [fn(): ChangeSourceFake => codeOwnersSource([Flows::HEAD => ['CODEOWNERS' => '* @root', 'docs/CODEOWNERS' => '* @docs']]), '@root', true],
    'under docs last' => [fn(): ChangeSourceFake => codeOwnersSource([Flows::HEAD => ['docs/CODEOWNERS' => '* @docs']]), '@docs', true],
    'an empty one first' => [fn(): ChangeSourceFake => codeOwnersSource([Flows::HEAD => ['.github/CODEOWNERS' => '', 'CODEOWNERS' => '* @root']]), 'unowned', true],
    'none' => [fn(): ChangeSourceFake => codeOwnersSource([Flows::HEAD => ['src/Money.php' => '<?php']]), 'unowned', false],
]);

it('reads no file where git cannot read the base, and says why', function (): void {
    $owners = CodeOwnersFile::of(codeOwnersSource([]), pullRequestPlan());

    expect($owners->wasRead())->toBeFalse()
        ->and(array_map(static fn(Warning $warning): string => $warning->text(), [...$owners->warnings()]))->toBe([
            'CODEOWNERS cannot be read at refs/remotes/origin/main, so no survivor has an owner: refs/remotes/origin/main is not a revision this repository has.',
        ]);
});
