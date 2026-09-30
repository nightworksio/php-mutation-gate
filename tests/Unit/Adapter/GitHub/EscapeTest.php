<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitHub\Escape;

it('leaves plain words as they are', function (): void {
    expect(Escape::text('src scores 82.14%, below its floor.'))->toBe('src scores 82.14%, below its floor.');
});

it('makes markup, links, mentions, emphasis and cell bars inert', function (): void {
    expect(Escape::text('<img src=x onerror=alert(1)> [x](https://evil) @octocat *a* _b_ ~c~ a|b \\d #e !f & "g" \'h\''))
        ->toBe('&lt;img src=x onerror=alert(1)&gt; &#91;x&#93;(https://evil) &#64;octocat &#42;a&#42; &#95;b&#95; &#126;c&#126; a&#124;b &#92;d &#35;e &#33;f &amp; &quot;g&quot; &apos;h&apos;');
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
