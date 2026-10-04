<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Opcache\Opcodes;

/** A dump of a program at a path, as opcache writes it, with this function body. */
function opcodesDump(string $path, string $line, string $body): string
{
    return implode("\n", [
        '',
        sprintf('{closure:App\K::run():%s}:', $line),
        '     ; (lines=2, args=0, vars=0, tmps=0)',
        '     ; (after optimizer)',
        sprintf('     ; %s:%s-%s', $path, $line, $line),
        $body,
    ]);
}

it('reads two copies of one program, compiled at two paths with their closures on other lines, as the same', function (): void {
    $one = Opcodes::dumped(opcodesDump('/a/1/program.php', '6', '0000 RETURN string("/a/1/program.php")'), '/a/1/program.php');
    $two = Opcodes::dumped(opcodesDump('/a/2/program.php', '9', '0000 RETURN string("/a/2/program.php")'), '/a/2/program.php');

    expect($one->same($two))->toBeTrue()
        ->and($one->text())->toBe(implode("\n", [
            '',
            '{closure:App\K::run()}:',
            '     ; (lines=2, args=0, vars=0, tmps=0)',
            '     ; (after optimizer)',
            sprintf('0000 RETURN string("%s")', Opcodes::FILE),
        ]));
});

it('names the program\'s directory alike, as written and as the dump escapes it', function (): void {
    $dump = opcodesDump('/a/b"c/program.php', '1', '0000 RETURN string("/a/b\\"c")');

    expect(Opcodes::dumped($dump, '/a/b"c/program.php')->text())->toEndWith(sprintf('0000 RETURN string("%s")', Opcodes::DIRECTORY));
});

it('keeps every literal and opcode line as it is, so a changed one never compares equal', function (string $before, string $after): void {
    $one = Opcodes::dumped(opcodesDump('/a/program.php', '6', $before), '/a/program.php');
    $two = Opcodes::dumped(opcodesDump('/a/program.php', '6', $after), '/a/program.php');

    expect($one->same($two))->toBeFalse();
})->with([
    'a literal that looks like a closure\'s line' => ['0000 RETURN string("x:5}")', '0000 RETURN string("x:6}")'],
    'a closure name among the opcodes' => ['0000 RETURN string("{closure:f():5}")', '0000 RETURN string("{closure:f():6}")'],
    'an opcode' => ['0000 T1 = IS_SMALLER CV0($a) int(1)', '0000 T1 = IS_SMALLER_OR_EQUAL CV0($a) int(1)'],
]);
