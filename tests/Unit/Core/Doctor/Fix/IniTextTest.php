<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Doctor\Fix\IniText;

it('sets a setting on the last line that sets it, leaving a commented line alone', function (): void {
    $ini = "; opcache.enable_cli=1\nopcache.enable_cli=1\nmemory_limit=128M\nopcache.enable_cli = 1\n";

    expect(IniText::setting($ini, 'opcache.enable_cli', '0'))
        ->toBe("; opcache.enable_cli=1\nopcache.enable_cli=1\nmemory_limit=128M\nopcache.enable_cli=0\n");
});

it('adds a setting no line sets at the end, after the newline its last line lacks', function (string $ini, string $set): void {
    expect(IniText::setting($ini, 'opcache.file_cache', ''))->toBe($set);
})->with([
    'ending in a newline' => ["memory_limit=128M\n", "memory_limit=128M\nopcache.file_cache=\n"],
    'ending without one' => ['memory_limit=128M', "memory_limit=128M\nopcache.file_cache=\n"],
    'empty' => ['', "opcache.file_cache=\n"],
]);

it('loads an extension no line loads, and leaves a text that loads it as it is', function (string $ini, string $loaded): void {
    expect(IniText::extension($ini, 'pcov'))->toBe($loaded);
})->with([
    'nothing loads it' => ["memory_limit=128M\n", "memory_limit=128M\nextension=pcov\n"],
    'only a comment names it' => [";extension=pcov\n", ";extension=pcov\nextension=pcov\n"],
    'a line loads it' => ["extension=pcov\n", "extension=pcov\n"],
    'a line loads its shared object, quoted' => ["extension = \"pcov.so\" ; coverage\n", "extension = \"pcov.so\" ; coverage\n"],
    'a line loads it on Windows' => ["extension=php_pcov.dll\n", "extension=php_pcov.dll\n"],
]);
