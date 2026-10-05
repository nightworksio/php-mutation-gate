<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Report\Escape;

it('leaves plain words as they are', function (): void {
    expect(Escape::text('src scores 82.14%, below its floor.'))->toBe('src scores 82.14%, below its floor.');
});

it('makes markup, links, mentions, emphasis and cell bars inert', function (): void {
    expect(Escape::text('<img src=x onerror=alert(1)> [x](https://evil) @octocat *a* _b_ ~c~ a|b \\d #e !f & "g" \'h\''))
        ->toBe('&lt;img src=x onerror=alert(1)&gt; &#91;x&#93;(https:&#47;&#47;evil) &#64;octocat &#42;a&#42; &#95;b&#95; &#126;c&#126; a&#124;b &#92;d &#35;e &#33;f &amp; &quot;g&quot; &apos;h&apos;');
});

it('shows what a hint puts in backticks as code, escaped as well', function (): void {
    expect(Escape::text('No test uses a value at the boundary of `$amount < $limit`.'))
        ->toBe('No test uses a value at the boundary of <code>$amount &lt; $limit</code>.');
});

it('keeps an unmatched backtick from opening code', function (): void {
    expect(Escape::text('a ` b'))->toBe('a <code> b</code>')
        ->and(Escape::code('`x`'))->toBe('<code>&#96;x&#96;</code>');
});

it('puts a line break nowhere a table row could end', function (): void {
    expect(Escape::text("one\ntwo\r\nthree"))->toBe('one two  three');
});

it('fences a block with more tildes than any run inside it', function (): void {
    expect(Escape::block('-  return 1;', 'diff'))->toBe("~~~diff\n-  return 1;\n~~~")
        ->and(Escape::block("~~~~\nx", 'diff'))->toBe("~~~~~diff\n~~~~\nx\n~~~~~");
});

it('drops every control character, an ANSI escape among them, from text the project controls', function (): void {
    expect(Escape::code("<script>alert(1)</script>|x\n\e[31mred"))
        ->toBe('<code>&lt;script&gt;alert(1)&lt;/script&gt;&#124;x &#91;31mred</code>')
        ->and(Escape::text("a\e[0m b\u{200B}c"))->toBe('a&#91;0m bc');
});

it('leaves no bare address GitHub would make a link of', function (string $text, string $escaped): void {
    expect(Escape::text($text))->toBe($escaped)
        ->and(Escape::code($text))->toBe(sprintf('<code>%s</code>', $escaped));
})->with([
    'a URL' => ['Reset it at https://evil.example/reset', 'Reset it at https:&#47;&#47;evil.example/reset'],
    'another scheme' => ['ftp://evil.example', 'ftp:&#47;&#47;evil.example'],
    'a host alone' => ['see www.evil.example', 'see www&#46;evil.example'],
    'a host in capitals' => ['WWW.evil.example', 'WWW&#46;evil.example'],
    'a word that only ends in www' => ['awww. ok', 'awww. ok'],
]);

it('drops control and format characters from a block, a bidirectional override among them, and keeps its tabs and lines', function (): void {
    expect(Escape::block("-\treturn \u{202E}1;\e[2K\r\n+\treturn 2;\u{200B}\x7F", 'diff'))
        ->toBe("~~~diff\n-\treturn 1;[2K\n+\treturn 2;\n~~~");
});
