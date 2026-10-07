<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Format\Secrets;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Withheld;

/** A withheld value with the characters each encoding treats apart: a slash, a plus, a quote, an accent and a space. */
const SCREENED = "battery/staple+horse'correct é value";

it('gives back what a process printed, as text a terminal shows safely, where it holds no secret', function (): void {
    expect(Secrets::of(SCREENED)->screened("all\x1b[31m fine\n", cut: false))->toBe("all[31m fine\n")
        ->and(Secrets::none()->screened('nothing withheld', cut: false))->toBe('nothing withheld');
});

it('keeps nothing of what a process printed where a withheld value appears in it, in any form a test can print it', function (string $printed): void {
    expect(Secrets::of(SCREENED, 'second-withheld-value')->screened($printed, cut: false))->toBeInstanceOf(NotGiven::class);
})->with([
    'as it is' => [sprintf('KEY=%s', SCREENED)],
    'quoted' => [sprintf('"%s"', SCREENED)],
    'in another case' => [mb_strtoupper(SCREENED)],
    'broken by a control character' => ["batt\x00ery/staple+horse'correct é value"],
    'url-encoded' => [rawurlencode(SCREENED)],
    'form-encoded' => [urlencode(SCREENED)],
    'base64-encoded, alone' => [sodium_bin2base64(SCREENED, SODIUM_BASE64_VARIANT_ORIGINAL)],
    'base64-encoded behind one byte' => [sodium_bin2base64(sprintf('x%s', SCREENED), SODIUM_BASE64_VARIANT_ORIGINAL)],
    'base64-encoded behind two bytes, in a header' => [sprintf('AUTH: basic %s', sodium_bin2base64(sprintf('u:%s;', SCREENED), SODIUM_BASE64_VARIANT_ORIGINAL))],
    'url-safe base64' => [sodium_bin2base64(sprintf('xy%s', SCREENED), SODIUM_BASE64_VARIANT_URLSAFE)],
    'hex-encoded' => [sodium_bin2hex(SCREENED)],
    'hex-encoded in capitals' => [mb_strtoupper(sodium_bin2hex(SCREENED))],
    'JSON-escaped' => [json_encode(['v' => SCREENED])],
    'JSON-escaped with its slashes and unicode as they are' => [json_encode(['v' => SCREENED], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
    'shell-escaped' => [escapeshellarg(SCREENED)],
    'backslash-escaped' => [addslashes(SCREENED)],
    'the second of two' => ['and second-withheld-value'],
    'one overlapping another' => [sprintf('%ssecond-withheld-value', mb_substr(SCREENED, 0, 20))],
]);

it('keeps nothing where a withheld value appears in a form only one escaping gives it', function (string $printed): void {
    expect(Secrets::of('a "quoted" \'value\' é / end')->screened($printed, cut: false))->toBeInstanceOf(NotGiven::class);
})->with([
    'JSON, its slashes and unicode escaped' => [json_encode('a "quoted" \'value\' é / end')],
    'JSON, its slashes as they are' => [json_encode('a "quoted" \'value\' é / end', JSON_UNESCAPED_SLASHES)],
    'JSON, its unicode as it is' => [json_encode('a "quoted" \'value\' é / end', JSON_UNESCAPED_UNICODE)],
    'JSON, both as they are' => [json_encode('a "quoted" \'value\' é / end', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
]);

/** Ten question marks in base64's URL alphabet, where the standard alphabet writes a slash. */
$urlSafe = sodium_bin2base64('??????????', SODIUM_BASE64_VARIANT_URLSAFE);

/** Ten question marks behind one byte and before two, in base64: their last group shares bytes with what follows. */
$embedded = sodium_bin2base64('x??????????yz', SODIUM_BASE64_VARIANT_ORIGINAL);

it('keeps nothing where a withheld value whose base64 holds a slash appears in the URL alphabet, or one holding a control character appears without it', function () use ($urlSafe, $embedded): void {
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
    'a private key' => [sprintf('-----BEGIN %s PRIVATE KEY-----', 'RSA')],
    'a GitHub token' => [sprintf('gh%s_%s', 'p', str_repeat('a1', 18))],
    'a fine-grained GitHub token' => [sprintf('github_%s_%s', 'pat', str_repeat('a1', 18))],
    'an AWS key id' => [sprintf('AK%s%s', 'IA', str_repeat('Z', 16))],
    'the token actions/checkout persists' => ['x-access-token:'],
    'an authorization header' => [sprintf('Authorization: %s abc', 'Bearer')],
    'a service account key' => ['"private_key": "'],
    'a Slack token' => [sprintf('xo%s-1234', 'xb')],
    'a GitLab token' => [sprintf('gl%s-%s', 'pat', str_repeat('a', 20))],
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
