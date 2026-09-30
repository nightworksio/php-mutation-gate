<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Report\Xml;

it('escapes markup and both quotes', function (): void {
    expect(Xml::text('<a href="x">it\'s & done</a>'))->toBe('&lt;a href=&quot;x&quot;&gt;it&apos;s &amp; done&lt;/a&gt;');
});

it('drops characters XML cannot hold, and replaces bytes that are not UTF-8', function (): void {
    expect(Xml::text("a\x00b\x1Bc\td\ne"))->toBe("abc\td\ne")
        ->and(Xml::text("tea\xE9"))->toBe('tea?');
});
