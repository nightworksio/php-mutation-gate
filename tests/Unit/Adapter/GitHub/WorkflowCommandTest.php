<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitHub\WorkflowCommand;

it('writes a command with its properties and message', function (): void {
    expect(WorkflowCommand::of('error', ['file' => 'src/Money.php', 'line' => 42, 'title' => 'Mutant survived: LessThan'], 'No test uses a value.'))
        ->toBe('::error file=src/Money.php,line=42,title=Mutant survived%3A LessThan::No test uses a value.');
});

it('escapes what could end a property or the command', function (): void {
    expect(WorkflowCommand::of('warning', ['file' => "a,b:c%d\re\nf"], "100% done\r\n::error::injected"))
        ->toBe('::warning file=a%2Cb%3Ac%25d%0De%0Af::100%25 done%0D%0A::error::injected');
});

it('drops every control and format character from a property and the message, so none reaches the log', function (): void {
    expect(WorkflowCommand::of('error', ['title' => "Mutant survived\e[8m: x\u{202E}"], "MoneyTest::fits \e]1338;url='https://attacker.example/p.png'\x07\e[2K"))
        ->toBe("::error title=Mutant survived[8m%3A x::MoneyTest::fits ]1338;url='https://attacker.example/p.png'[2K");
});
