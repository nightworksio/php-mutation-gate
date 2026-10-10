<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Format\Secrets;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Withheld;

/** Bytes in base64, in the variant sodium names, as the screen reads them. */
function secretsBase64(string $bytes, int $variant): string
{
    return sodium_bin2base64($bytes, $variant);
}

/** Bytes in hex, as the screen reads them. */
function secretsHex(string $bytes): string
{
    return sodium_bin2hex($bytes);
}

/** A withheld value with the characters each encoding treats apart: a slash, a plus, a quote, an accent and a space. */
const SCREENED = "battery/staple+horse'correct é value";

it('gives back what a process printed, as text a terminal shows safely, where it holds no secret', function (): void {
    expect(Secrets::of(SCREENED)->screened("all\x1b[31m fine\n", cut: false))->toBe("all[31m fine\n")
        ->and(Secrets::none()->screened('nothing withheld', cut: false))->toBe('nothing withheld');
});

it('keeps nothing of what a process printed where a withheld value appears in it, in any form a test can print it', function (string $printed): void {
    expect(Secrets::of(SCREENED, 'second-withheld-value')->screened($printed, cut: false))->toBeInstanceOf(NotGiven::class);
})->with([
    'as it is' => [fn(): string => sprintf('KEY=%s', SCREENED)],
    'quoted' => [fn(): string => sprintf('"%s"', SCREENED)],
    'in another case' => [fn(): string => mb_strtoupper(SCREENED)],
    'broken by a control character' => ["batt\x00ery/staple+horse'correct é value"],
    'url-encoded' => [fn(): string => rawurlencode(SCREENED)],
    'form-encoded' => [fn(): string => urlencode(SCREENED)],
    'base64-encoded, alone' => [fn(): string => secretsBase64(SCREENED, SODIUM_BASE64_VARIANT_ORIGINAL)],
    'base64-encoded behind one byte' => [fn(): string => secretsBase64(sprintf('x%s', SCREENED), SODIUM_BASE64_VARIANT_ORIGINAL)],
    'base64-encoded behind two bytes, in a header' => [fn(): string => sprintf('AUTH: basic %s', secretsBase64(sprintf('u:%s;', SCREENED), SODIUM_BASE64_VARIANT_ORIGINAL))],
    'url-safe base64' => [fn(): string => secretsBase64(sprintf('xy%s', SCREENED), SODIUM_BASE64_VARIANT_URLSAFE)],
    'hex-encoded' => [fn(): string => secretsHex(SCREENED)],
    'hex-encoded in capitals' => [fn(): string => mb_strtoupper(secretsHex(SCREENED))],
    'JSON-escaped' => [fn(): string => (string) json_encode(['v' => SCREENED])],
    'JSON-escaped with its slashes and unicode as they are' => [fn(): string => (string) json_encode(['v' => SCREENED], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
    'shell-escaped' => [fn(): string => escapeshellarg(SCREENED)],
    'backslash-escaped' => [fn(): string => addslashes(SCREENED)],
    'the second of two' => ['and second-withheld-value'],
    'one overlapping another' => [fn(): string => sprintf('%ssecond-withheld-value', mb_substr(SCREENED, 0, 20))],
]);

it('keeps nothing where a withheld value appears in a form only one escaping gives it', function (string $printed): void {
    expect(Secrets::of('a "quoted" \'value\' é / end')->screened($printed, cut: false))->toBeInstanceOf(NotGiven::class);
})->with([
    'JSON, its slashes and unicode escaped' => [fn(): string => (string) json_encode('a "quoted" \'value\' é / end')],
    'JSON, its slashes as they are' => [fn(): string => (string) json_encode('a "quoted" \'value\' é / end', JSON_UNESCAPED_SLASHES)],
    'JSON, its unicode as it is' => [fn(): string => (string) json_encode('a "quoted" \'value\' é / end', JSON_UNESCAPED_UNICODE)],
    'JSON, both as they are' => [fn(): string => (string) json_encode('a "quoted" \'value\' é / end', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
]);

/** Ten question marks in base64's URL alphabet, where the standard alphabet writes a slash. */
$makeUrlSafe = static fn(): string => secretsBase64('??????????', SODIUM_BASE64_VARIANT_URLSAFE);

/** Ten question marks behind one byte and before two, in base64: their last group shares bytes with what follows. */
$makeEmbedded = static fn(): string => secretsBase64('x??????????yz', SODIUM_BASE64_VARIANT_ORIGINAL);

it('keeps nothing where a withheld value whose base64 holds a slash appears in the URL alphabet, or one holding a control character appears without it', function () use ($makeUrlSafe, $makeEmbedded): void {
    $urlSafe = $makeUrlSafe();
    $embedded = $makeEmbedded();

    expect(Secrets::of('??????????')->screened($urlSafe, cut: false))->toBeInstanceOf(NotGiven::class)
        ->and(Secrets::of('??????????')->screened($embedded, cut: false))->toBeInstanceOf(NotGiven::class)
        ->and(Secrets::of("abc\x01defghij")->screened("before abc\x01defghij after", cut: false))->toBeInstanceOf(NotGiven::class);
});

it('drops from a cut print exactly the bytes the longest form holds, from the first character past them', function (): void {
    // The longest form of ten ASCII letters is their hex, of twenty bytes; the 20th byte is the second of é.
    expect(Secrets::of('abcdefghij')->screened(sprintf('%séqrstuv', str_repeat('z', 19)), cut: true))->toBe('qrstuv')
        ->and(Secrets::of('abcdefghij')->screened('short', cut: true))->toBe('');
});

it('keeps nothing where a withheld value stored with whitespace around it appears without, or one line of a value of many lines does', function (): void {
    $pem = sprintf("-----BEGIN KEY-----\n%s\n%s\n-----END KEY-----\n", str_repeat('A1b2', 16), str_repeat('C3d4', 16));
    $secrets = Secrets::of(sprintf("  %s\n", SCREENED), $pem);

    expect($secrets->screened(sprintf('token %s.', SCREENED), cut: false))->toBeInstanceOf(NotGiven::class)
        ->and($secrets->screened(sprintf('line %s', str_repeat('C3d4', 16)), cut: false))->toBeInstanceOf(NotGiven::class)
        ->and(Secrets::of("abcd\nefgh\nijkl")->screened("printed abcd\nefgh\nijkl whole", cut: false))->toBeInstanceOf(NotGiven::class)
        ->and(Secrets::of("  abcd\nefgh\nijkl\n")->screened("printed abcd\nefgh\nijkl whole", cut: false))->toBeInstanceOf(NotGiven::class);
});

it('keeps nothing where what a process printed has the shape of a credential, whatever the gate withholds', function (string $printed): void {
    expect(Secrets::none()->screened(sprintf('before %s after', $printed), cut: false))->toBeInstanceOf(NotGiven::class);
})->with([
    'a private key' => [fn(): string => sprintf('-----BEGIN %s PRIVATE KEY-----', 'RSA')],
    'a GitHub token' => [fn(): string => sprintf('gh%s_%s', 'p', str_repeat('a1', 18))],
    'a fine-grained GitHub token' => [fn(): string => sprintf('github_%s_%s', 'pat', str_repeat('a1', 18))],
    'an AWS key id' => [fn(): string => sprintf('AK%s%s', 'IA', str_repeat('Z', 16))],
    'the token actions/checkout persists' => ['x-access-token:'],
    'an authorization header' => [fn(): string => sprintf('Authorization: %s abc', 'Bearer')],
    'a service account key' => ['"private_key": "'],
    'a Slack token' => [fn(): string => sprintf('xo%s-1234', 'xb')],
    'a GitLab token' => [fn(): string => sprintf('gl%s-%s', 'pat', str_repeat('a', 20))],
]);

it('drops what a cut leaves of a withheld value at the start of what a process printed, however many control characters follow it', function (): void {
    $fragment = mb_substr(SCREENED, 9);
    $padded = sprintf("%s%s the end", $fragment, str_repeat("\x00", 6000));
    $long = sprintf('%s%s the end', $fragment, str_repeat('z', 200));

    expect(Secrets::of(SCREENED)->screened($long, cut: true))->not->toContain($fragment)
        ->and(Secrets::of(SCREENED)->screened($long, cut: true))->toEndWith('zz the end')
        ->and(Secrets::of(SCREENED)->screened($padded, cut: true))->toBe(' the end')
        ->and(Secrets::of(SCREENED)->screened(sprintf('%s the end', $fragment), cut: false))->toBe(sprintf('%s the end', $fragment))
        ->and(Secrets::none()->screened('whole', cut: true))->toBe('whole');
});

it('takes as secrets the values of the variables the gate withholds, each long enough not to be a word', function (): void {
    $variables = Variables::of([
        'GITHUB_TOKEN' => 'withheld-github-value',
        'AWS_REGION' => 'eu-west-1',
        'ACTIONS_STEP_DEBUG' => 'true',
        'HOME' => '/home/runner/longer-than-eight',
        'SONAR_TOKEN' => 'eightchr',
        'AWS_PROFILE' => 'sevench',
    ]);
    $secrets = Secrets::withheldIn($variables, Withheld::standard());

    expect($secrets->screened('the withheld-github-value', cut: false))->toBeInstanceOf(NotGiven::class)
        ->and($secrets->screened('in eu-west-1', cut: false))->toBeInstanceOf(NotGiven::class)
        ->and($secrets->screened('an eightchr', cut: false))->toBeInstanceOf(NotGiven::class)
        ->and($secrets->screened('true /home/runner/longer-than-eight sevench', cut: false))
        ->toBe('true /home/runner/longer-than-eight sevench');
});

it('keeps nothing where one value of a withheld value that holds several appears alone', function (string $withheld, string $printed): void {
    expect(Secrets::of($withheld)->screened(sprintf('printed %s here', $printed), cut: false))->toBeInstanceOf(NotGiven::class);
})->with([
    'a string in JSON, as COMPOSER_AUTH holds a token' => ['{"gitlab-token": {"gitlab.example.com": "withheld-json-leaf"}}', 'withheld-json-leaf'],
    'a string in a list in JSON' => ['{"tokens": ["withheld-list-leaf"]}', 'withheld-list-leaf'],
    'a value in a list of pairs, as an OpenTelemetry header holds a key' => ['x-honeycomb-team=withheld-pair-value,x-other=short', 'withheld-pair-value'],
    'a word after a scheme, as an authorization value holds a token' => ['Bearer withheld-bearer-word', 'withheld-bearer-word'],
    'the password in a URL' => ['https://deploy:withheld-url-password@registry.example.com/simple', 'withheld-url-password'],
]);

it('keeps nothing where a withheld value appears broken by an escape sequence or by whitespace', function (string $printed): void {
    expect(Secrets::of('withheld-broken-value')->screened($printed, cut: false))->toBeInstanceOf(NotGiven::class);
})->with([
    'coloured in its middle' => ["withheld-\x1b[1;31mbroken\x1b[0m-value"],
    'linked by a terminal hyperlink' => ["withheld-\x1b]8;;https://example.com\x07broken-value"],
    'wrapped across lines' => ["withheld-bro\n  ken-value"],
    'base64 wrapped as MIME wraps it' => [fn(): string => chunk_split(secretsBase64(str_repeat('withheld-broken-value', 4), SODIUM_BASE64_VARIANT_ORIGINAL), 16, "\r\n")],
]);

it('keeps nothing where a withheld value appears as HTML, SQL or var_export escape it', function (string $printed): void {
    // No word of it is long enough to be screened for alone.
    expect(Secrets::of('it\'s <a> "with" & more!')->screened($printed, cut: false))->toBeInstanceOf(NotGiven::class);
})->with([
    'HTML 4, its quote a number' => [fn(): string => htmlspecialchars('it\'s <a> "with" & more!', ENT_QUOTES | ENT_HTML401)],
    'HTML 5, its quote named' => [fn(): string => htmlspecialchars('it\'s <a> "with" & more!', ENT_QUOTES | ENT_HTML5)],
    'SQL, its quote doubled' => ['\'it\'\'s <a> "with" & more!\''],
    'var_export' => [fn(): string => var_export('it\'s <a> "with" & more!', return: true)],
]);

it('compares without whitespace a form that keeps the fewest characters without it', function (): void {
    expect(Secrets::of('abcd efgh')->screened("wrapped abcd\nefgh here", cut: false))->toBeInstanceOf(NotGiven::class)
        ->and(Secrets::of('abc def g')->screened("abc\ndef g", cut: false))->toBe("abc\ndef g");
});

it('screens for no piece of a withheld value too short to be a secret, however long its encoding', function (): void {
    $secrets = Secrets::of("abcd\nlong-enough-line <a>");

    expect($secrets->screened('hex 61626364 and &lt;a&gt; are fine', cut: false))->toBe('hex 61626364 and &lt;a&gt; are fine');
});

it('keeps nothing where a withheld value of short words appears in a form only one encoding gives it', function (string $printed): void {
    // No word of it is long enough to be screened for alone, so only the whole value's forms find it.
    expect(Secrets::of('ab/c "d" e\'f g+h')->screened(sprintf('printed %s here', $printed), cut: false))->toBeInstanceOf(NotGiven::class);
})->with([
    'url-encoded, its spaces %20' => [fn(): string => rawurlencode('ab/c "d" e\'f g+h')],
    'form-encoded, its spaces +' => [fn(): string => urlencode('ab/c "d" e\'f g+h')],
    'backslash-escaped' => [fn(): string => addslashes('ab/c "d" e\'f g+h')],
    'shell-escaped' => [fn(): string => escapeshellarg('ab/c "d" e\'f g+h')],
]);

it('keeps nothing where one line of a withheld value of many appears alone, though no word of it is long enough', function (): void {
    expect(Secrets::of("first line\nsecond line here")->screened('printed second line here', cut: false))->toBeInstanceOf(NotGiven::class);
});

/** Twelve bytes are four whole groups of three; these share the first three groups with `abcdefghijkl` and not the fourth. */
$makeSharingGroups = static fn(): string => sprintf('header %s', secretsBase64('abcdefghiXYZ', SODIUM_BASE64_VARIANT_ORIGINAL));

it('keeps what a process printed where only the first base64 groups of a withheld value appear, and its last group differs', function () use ($makeSharingGroups): void {
    $sharingGroups = $makeSharingGroups();

    expect(Secrets::of('abcdefghijkl')->screened($sharingGroups, cut: false))->toBe($sharingGroups);
});
