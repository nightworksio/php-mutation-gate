<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\BuiltinReporter;
use NightWorksIO\MutationGate\Core\Config\EntryPath;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Runner\GateSecret;

it('names each built-in reporter by the name a config chooses it by', function (): void {
    foreach (BuiltinReporter::cases() as $builtin) {
        expect($builtin->named())->toEqual(Name::of($builtin->value));
    }
});

it('says whether an entry of each reporter names a path', function (BuiltinReporter $reporter, EntryPath $path): void {
    expect($reporter->entryPath())->toBe($path);
})->with([
    [BuiltinReporter::Json, EntryPath::Required],
    [BuiltinReporter::JUnit, EntryPath::Required],
    [BuiltinReporter::Sarif, EntryPath::Required],
    [BuiltinReporter::Html, EntryPath::Required],
    [BuiltinReporter::Digest, EntryPath::Required],
    [BuiltinReporter::Tests, EntryPath::Required],
    [BuiltinReporter::KillMatrix, EntryPath::Required],
    [BuiltinReporter::GitLab, EntryPath::Required],
    [BuiltinReporter::Sonar, EntryPath::Required],
    [BuiltinReporter::Badge, EntryPath::Optional],
    [BuiltinReporter::Console, EntryPath::Refused],
    [BuiltinReporter::Problems, EntryPath::Refused],
    [BuiltinReporter::Slack, EntryPath::Refused],
    [BuiltinReporter::Discord, EntryPath::Refused],
    [BuiltinReporter::Webhook, EntryPath::Refused],
    [BuiltinReporter::Otlp, EntryPath::Refused],
    [BuiltinReporter::GitHubAnnotations, EntryPath::Refused],
    [BuiltinReporter::GitHubSummary, EntryPath::Refused],
    [BuiltinReporter::GitHubComment, EntryPath::Refused],
    [BuiltinReporter::GitLabComment, EntryPath::Refused],
    [BuiltinReporter::BitbucketComment, EntryPath::Refused],
    [BuiltinReporter::BitbucketInsights, EntryPath::Refused],
]);

it('says which reporters send an alert', function (): void {
    foreach (BuiltinReporter::cases() as $reporter) {
        expect($reporter->alerts())
            ->toBe(in_array($reporter, [BuiltinReporter::Slack, BuiltinReporter::Discord, BuiltinReporter::Webhook], strict: true));
    }
});

it('names the variable each comment that needs a token reads it from, and none for any other reporter', function (): void {
    $tokens = [];

    foreach (BuiltinReporter::cases() as $reporter) {
        $token = $reporter->tokenEnv();
        $tokens = $token instanceof GateSecret ? [...$tokens, $reporter->value => $token] : $tokens;
    }

    expect($tokens)->toBe(['gitlab-comment' => GateSecret::GitLabToken, 'bitbucket-comment' => GateSecret::BitbucketToken]);
});
