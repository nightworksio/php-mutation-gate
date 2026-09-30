<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Report\Csv;

it('writes a record as RFC 4180 does, quoting only a cell that needs it', function (): void {
    expect(Csv::record('a', 'b c', 'd,e', 'say "hi"', "two\nlines", "cr\rhere", ''))
        ->toBe("a,b c,\"d,e\",\"say \"\"hi\"\"\",\"two\nlines\",\"cr\rhere\",\r\n");
});

it('keeps a cell a spreadsheet would run as a formula as text', function (string $cell, string $written): void {
    expect(Csv::record($cell))->toBe(sprintf("%s\r\n", $written));
})->with([
    'equals' => ['=HYPERLINK("x")', "\"'=HYPERLINK(\"\"x\"\")\""],
    'plus' => ['+1', "'+1"],
    'minus' => ['-1', "'-1"],
    'at' => ['@SUM(A1)', "'@SUM(A1)"],
    'tab' => ["\tx", "'\tx"],
    'carriage return' => ["\rx", "\"'\rx\""],
    'a formula sign inside' => ['a=b', 'a=b'],
]);
