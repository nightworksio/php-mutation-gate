<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Doctor\Fix\FixText;
use NightWorksIO\MutationGate\Tests\Support\Described;
use NightWorksIO\MutationGate\Tests\Support\DoctorRuns;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

$makeHere = static fn(): string => (string) getcwd();

afterEach(function () use ($makeHere): void {
    $here = $makeHere();

    chdir($here);
    Scratch::sweep();
});

it('cannot judge a format it does not write', function (): void {
    expect(DoctorRuns::over('tests/Fixtures/Projects/Library', ['--format' => 'xml']))
        ->toBe([2, '', "--format is xml; doctor writes text or json.\n"]);
});

it('shows what --fix would change, and writes nothing where nobody can answer', function (): void {
    [$code, $output, , $project] = DoctorRuns::fixing('tests/Fixtures/Projects/Library', ['--fix' => true], ['.gitignore' => "vendor/\n"]);

    expect($code)->toBe(0)
        ->and($output)->toEndWith(implode("\n", [
            'advice.',
            '',
            'doctor --fix would change:',
            '',
            '--- a/.gitignore',
            '+++ b/.gitignore',
            '@@ -1,1 +1,2 @@',
            ' vendor/',
            '+.mutation-gate/',
            FixText::UNWRITTEN,
            '',
        ]))
        ->and((string) file_get_contents(sprintf('%s/.gitignore', $project)))->toBe("vendor/\n");
});

it('writes what --yes answers for, and looks again', function (): void {
    [$code, $output, , $project] = DoctorRuns::fixing('tests/Fixtures/Projects/Library', ['--fix' => true, '--yes' => true], ['.gitignore' => "vendor/\n"]);

    expect($code)->toBe(0)
        ->and($output)->toContain("+.mutation-gate/\n\nWrote 1 file.\n\nadvice  ")
        ->and(substr_count($output, 'workspace-not-ignored'))->toBe(2)
        ->and((string) file_get_contents(sprintf('%s/.gitignore', $project)))->toBe("vendor/\n.mutation-gate/\n");
});

it('asks once, and writes on yes and leaves everything as it was on no', function (string $answer, string $gitIgnore, string $after): void {
    [, $output, $errors, $project] = DoctorRuns::fixing('tests/Fixtures/Projects/Library', ['--fix' => true], ['.gitignore' => "vendor/\n"], $answer);

    expect($errors)->toContain('Apply 1 change to 1 file? [y/N]')
        ->and($output)->not->toContain(FixText::UNWRITTEN)
        ->and(substr(explode("+.mutation-gate/\n", $output, 2)[1], 0, strlen($after)))->toBe($after)
        ->and((string) file_get_contents(sprintf('%s/.gitignore', $project)))->toBe($gitIgnore);
})->with([
    'yes' => ['y', "vendor/\n.mutation-gate/\n", "\nWrote 1 file.\n"],
    'no' => ['n', "vendor/\n", "\n"],
]);

it('reads GitHub\'s settings only where --online asks it to', function (): void {
    [, $online] = DoctorRuns::fixing('tests/Fixtures/Projects/Library', ['--online' => true]);
    [, $offline] = DoctorRuns::fixing('tests/Fixtures/Projects/Library');

    expect($online)->toContain('no-github-repository')
        ->and($offline)->not->toContain('no-github-repository');
});

it('turns opcache off in the php.ini the runner\'s PHP loads, and names what it cannot fix', function (): void {
    $directory = Scratch::directory();
    Scratch::write($directory, 'php.ini', "opcache.enable_cli=1\n");
    $ini = sprintf('%s/php.ini', $directory);
    $described = Described::loading($ini, ['Core' => '8.5.0', 'Zend OPcache' => '8.5.0'], ['opcache.enable_cli' => '1', 'opcache.file_cache' => '']);
    [, $output] = DoctorRuns::fixing('tests/Fixtures/Projects/Library', ['--fix' => true, '--yes' => true], [], described: $described);

    expect((string) file_get_contents($ini))->toBe("opcache.enable_cli=0\nopcache.file_cache=\n")
        ->and($output)->toContain('- no-coverage-driver: Install pcov with pecl install pcov, then run doctor --fix again.');
});

it('refuses --format=json with --fix, which shows diffs and asks', function (): void {
    expect(DoctorRuns::over('tests/Fixtures/Projects/Library', ['--fix' => true, '--format' => 'json']))
        ->toBe([2, '', "--fix shows diffs and asks, so it writes text: leave out --format=json.\n"]);
});

it('says it has nothing to change where it can fix nothing it found', function (): void {
    [$code, $output] = DoctorRuns::fixing('tests/Fixtures/Projects/Library', ['--fix' => true]);

    expect($code)->toBe(0)
        ->and($output)->toEndWith(sprintf("\n\n%s\n", FixText::NOTHING))
        ->and($output)->not->toContain(FixText::UNWRITTEN);
});
