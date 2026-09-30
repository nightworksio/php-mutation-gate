<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\BuiltinReporter;
use NightWorksIO\MutationGate\Core\Config\EntryPath;
use NightWorksIO\MutationGate\Core\Config\Name;

it('names each built-in reporter by the name a config chooses it by', function (BuiltinReporter $builtin): void {
    expect($builtin->named())->toEqual(Name::of($builtin->value));
})->with(BuiltinReporter::cases());

it('says whether an entry of each reporter names a path', function (BuiltinReporter $reporter, EntryPath $path): void {
    expect($reporter->entryPath())->toBe($path);
})->with([
    [BuiltinReporter::Json, EntryPath::Required],
    [BuiltinReporter::JUnit, EntryPath::Required],
    [BuiltinReporter::Sarif, EntryPath::Required],
    [BuiltinReporter::Html, EntryPath::Required],
    [BuiltinReporter::Tests, EntryPath::Required],
    [BuiltinReporter::KillMatrix, EntryPath::Required],
    [BuiltinReporter::GitLab, EntryPath::Required],
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
]);
