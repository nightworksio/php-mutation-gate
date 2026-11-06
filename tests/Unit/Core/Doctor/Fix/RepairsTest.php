<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Doctor\Fix\Change;
use NightWorksIO\MutationGate\Core\Doctor\Fix\Edit;
use NightWorksIO\MutationGate\Core\Doctor\Fix\FixText;
use NightWorksIO\MutationGate\Core\Doctor\Fix\Lines;
use NightWorksIO\MutationGate\Core\Doctor\Fix\Repair;
use NightWorksIO\MutationGate\Core\Doctor\Fix\Repairs;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

$appending = static fn(string $file, string $line): Change => Change::of($file, static fn(string $text): string => Lines::appended($text, $line));

it('applies every change of one file in turn, from the text it holds, and leaves out a file it leaves as it is', function () use ($appending): void {
    $repairs = Repairs::of(
        Repair::changing(Slug::OpcacheOnTheCommandLine, $appending('/etc/php.ini', 'a=0')),
        Repair::refused(Slug::NoCoverageDriver, 'Install pcov.'),
        Repair::changing(Slug::WorkspaceNotIgnored, $appending('.gitignore', 'b/'), Change::of('same.txt', static fn(string $text): string => $text)),
        Repair::changing(Slug::XdebugSlowsTests, $appending('/etc/php.ini', 'c=1')),
    );
    $read = static fn(string $file): string => $file === '/etc/php.ini' ? "x=1\n" : '';

    expect($repairs->edits($read))->toEqual([
        Edit::of('/etc/php.ini', "x=1\n", "x=1\na=0\nc=1\n"),
        Edit::of('.gitignore', '', "b/\n"),
    ]);
});

it('shows every diff, then what it cannot fix, and asks how many changes to how many files', function () use ($appending): void {
    $repairs = Repairs::of(
        Repair::changing(Slug::WorkspaceNotIgnored, $appending('.gitignore', '.mutation-gate/')),
        Repair::refused(Slug::NoCoverageDriver, 'Install pcov.'),
    );
    $edits = $repairs->edits(static fn(): string => "vendor/\n");

    expect(FixText::preview($repairs, $edits))->toBe(implode("\n", [
        'doctor --fix would change:',
        '',
        '--- a/.gitignore',
        '+++ b/.gitignore',
        '@@ -1,1 +1,2 @@',
        ' vendor/',
        '+.mutation-gate/',
        '',
        'doctor --fix cannot fix:',
        '- no-coverage-driver: Install pcov.',
    ]))
        ->and(FixText::question($repairs, $edits))->toBe('Apply 1 change to 1 file?')
        ->and(FixText::question(Repairs::of(
            Repair::changing(Slug::WorkspaceNotIgnored, $appending('a', 'x'), $appending('b', 'y')),
            Repair::changing(Slug::PestUnpatched, $appending('b', 'z')),
        ), [Edit::of('a', '', 'x'), Edit::of('b', '', 'y')]))->toBe('Apply 2 changes to 2 files?')
        ->and(FixText::wrote(1))->toBe('Wrote 1 file.')
        ->and(FixText::wrote(3))->toBe('Wrote 3 files.');
});

it('says it has nothing to change where nothing would change', function (): void {
    expect(FixText::preview(Repairs::of(), []))->toBe(FixText::NOTHING);
});

it('knows each repair by its finding, and a refusal by its reason', function (): void {
    $refused = Repair::refused(Slug::NoCoverageDriver, 'Install pcov.');
    $changing = Repair::changing(Slug::WorkspaceNotIgnored);

    expect([$refused->slug(), $refused->isRefused(), $refused->refusal(), $refused->changes()])->toBe([Slug::NoCoverageDriver, true, 'Install pcov.', []])
        ->and([$changing->isRefused(), $changing->refusal()])->toBe([false, ''])
        ->and(Edit::of('a', 'x', 'x')->isNone())->toBeTrue()
        ->and(Edit::of('a', 'x', 'y')->isNone())->toBeFalse();
});
