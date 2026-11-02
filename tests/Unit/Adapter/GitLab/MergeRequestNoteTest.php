<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitLab\GitLabPlan;
use NightWorksIO\MutationGate\Adapter\GitLab\MergeRequestNote;
use NightWorksIO\MutationGate\Adapter\GitLab\NoteMarkdown;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Delivery\CommentStates;
use NightWorksIO\MutationGate\Core\Delivery\Delivery;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Report\Markdown;
use NightWorksIO\MutationGate\Core\Report\PlannedMarkdown;
use NightWorksIO\MutationGate\Core\Report\RecheckedMarkdown;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\Cloud;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Rechecks;
use NightWorksIO\MutationGate\Tests\Support\ShardedPlan;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

const GITLAB_API = 'https://gitlab.example/api/v4';

const GITLAB_NOTES = 'https://gitlab.example/api/v4/projects/42/merge_requests/7/notes';

const GITLAB_PIPELINE = 'https://gitlab.example/acme/shop/-/pipelines/99';

/**
 * The variables of a merge request pipeline's job in the project acme/shop, holding a token, with these set.
 *
 * @param array<string, string> $with
 */
function gitlabMergeRequest(array $with = []): Variables
{
    return Variables::of([
        'GITLAB_CI' => 'true',
        'CI_API_V4_URL' => GITLAB_API,
        'CI_PROJECT_ID' => '42',
        'CI_PROJECT_URL' => 'https://gitlab.example/acme/shop',
        'CI_PIPELINE_URL' => GITLAB_PIPELINE,
        'CI_MERGE_REQUEST_IID' => '7',
        'CI_MERGE_REQUEST_PROJECT_ID' => '42',
        'CI_MERGE_REQUEST_PROJECT_URL' => 'https://gitlab.example/acme/shop',
        'MUTATION_GATE_GITLAB_TOKEN' => 'glpat-secret',
        ...$with,
    ]);
}

/**
 * A note as GitLab's notes API lists it.
 *
 * @return array<string, mixed>
 */
function gitlabNote(int $id, string $author, string $body, bool $system = false): array
{
    return ['id' => $id, 'body' => $body, 'author' => ['username' => $author], ...$system ? ['system' => true] : []];
}

/** The page of notes the note asks for first. */
function gitlabNotesPage(int $page): string
{
    return sprintf('%s?sort=asc&order_by=created_at&per_page=100&page=%d', GITLAB_NOTES, $page);
}

/** A cloud where the token belongs to the bot user and the merge request holds these notes on its first page. */
function gitlabHolding(string $notes): Cloud
{
    return new Cloud()
        ->answering(sprintf('%s/user', GITLAB_API), 200, '{"username": "project_42_bot"}')
        ->answering(gitlabNotesPage(1), 200, $notes);
}

it('posts a new note where the token\'s user has none, with the token, linking the pipeline', function (): void {
    $cloud = gitlabHolding((string) json_encode([
        gitlabNote(1, 'someone', Markdown::MARKER),
        gitlabNote(2, 'project_42_bot', 'no marker'),
        gitlabNote(3, 'project_42_bot', Markdown::MARKER, system: true),
    ]))->answering(GITLAB_NOTES, 201, '{"id": 11}');
    $answer = MergeRequestNote::inEnvironment(gitlabMergeRequest(), $cloud->exchange(), 'MUTATION_GATE_GITLAB_TOKEN')
        ->report(Verdicts::failing());
    $posted = $cloud->requests[2];

    expect($answer)->toEqual(Written::to('https://gitlab.example/acme/shop/-/merge_requests/7#note_11'))
        ->and(array_column($cloud->requests, 'method'))->toBe(['GET', 'GET', 'POST'])
        ->and($posted['url'])->toBe(GITLAB_NOTES)
        ->and($posted['headers']['authorization'] ?? '')->toBe('Bearer glpat-secret')
        ->and($posted['headers']['content-type'] ?? '')->toBe('application/json')
        ->and(Decoded::at($posted['body'], 'body'))->toBe(NoteMarkdown::of(Markdown::comment(Verdicts::failing()), GITLAB_PIPELINE))
        ->and(Decoded::at($posted['body'], 'body'))->toContain(sprintf('[The run](%s)', GITLAB_PIPELINE));
});

it('puts its own note in place, passing runs included, reading every full page of notes', function (): void {
    $full = array_map(static fn(int $id): array => gitlabNote($id, 'someone', 'text'), range(1, 100));
    $cloud = gitlabHolding((string) json_encode($full))
        ->answering(gitlabNotesPage(2), 200, (string) json_encode([gitlabNote(101, 'project_42_bot', sprintf("%s\nold", Markdown::MARKER))]))
        ->answering(sprintf('%s/101', GITLAB_NOTES), 200, '{"id": 101}');
    $answer = MergeRequestNote::inEnvironment(gitlabMergeRequest(), $cloud->exchange(), 'MUTATION_GATE_GITLAB_TOKEN')
        ->report(Verdicts::passing());

    expect($answer)->toEqual(Written::to('https://gitlab.example/acme/shop/-/merge_requests/7#note_101'))
        ->and(array_column($cloud->requests, 'method'))->toBe(['GET', 'GET', 'GET', 'PUT'])
        ->and($cloud->requests[3]['url'])->toBe(sprintf('%s/101', GITLAB_NOTES));
});

it('reads no page past the one that holds its own note', function (): void {
    $full = [...array_map(static fn(int $id): array => gitlabNote($id, 'someone', 'text'), range(1, 99)), gitlabNote(100, 'project_42_bot', Markdown::MARKER)];
    $cloud = gitlabHolding((string) json_encode($full))
        ->answering(gitlabNotesPage(2), 200, '[]')
        ->answering(sprintf('%s/100', GITLAB_NOTES), 200, '{"id": 100}');
    $answer = MergeRequestNote::inEnvironment(gitlabMergeRequest(), $cloud->exchange(), 'MUTATION_GATE_GITLAB_TOKEN')
        ->report(Verdicts::passing());

    expect($answer)->toEqual(Written::to('https://gitlab.example/acme/shop/-/merge_requests/7#note_100'))
        ->and(array_column($cloud->requests, 'url'))->not->toContain(gitlabNotesPage(2));
});

it('reads thirty full pages of notes for its own, and no more', function (): void {
    $full = (string) json_encode(array_map(static fn(int $id): array => gitlabNote($id, 'someone', 'text'), range(1, 100)));
    $cloud = new Cloud()->answering(sprintf('%s/user', GITLAB_API), 200, '{"username": "project_42_bot"}')
        ->answering(GITLAB_NOTES, 201, '{"id": 1}');

    foreach (range(1, 31) as $page) {
        $cloud->answering(gitlabNotesPage($page), 200, $full);
    }

    MergeRequestNote::inEnvironment(gitlabMergeRequest(), $cloud->exchange(), 'MUTATION_GATE_GITLAB_TOKEN')->report(Verdicts::passing());

    expect(array_column($cloud->requests, 'url'))->not->toContain(gitlabNotesPage(31))
        ->and(array_column($cloud->requests, 'url'))->toContain(gitlabNotesPage(30))
        ->and(array_column($cloud->requests, 'method'))->toContain('POST');
});

it('writes its planned state into the note the verdict later replaces', function (): void {
    $cloud = gitlabHolding((string) json_encode([gitlabNote(5, 'project_42_bot', Markdown::MARKER)]))
        ->answering(sprintf('%s/5', GITLAB_NOTES), 200, '{"id": 5}');
    $answer = MergeRequestNote::inEnvironment(gitlabMergeRequest(), $cloud->exchange(), 'MUTATION_GATE_GITLAB_TOKEN')
        ->planned(ShardedPlan::planned(2));

    expect($answer)->toEqual(Written::to('https://gitlab.example/acme/shop/-/merge_requests/7#note_5'))
        ->and(Decoded::at($cloud->requests[2]['body'], 'body'))
        ->toBe(NoteMarkdown::of(PlannedMarkdown::comment(ShardedPlan::planned(2), GITLAB_PIPELINE), GITLAB_PIPELINE));
});

it('writes the re-checked survivors over its planned state alone', function (string $notes, bool $written): void {
    $cloud = gitlabHolding($notes)->answering(sprintf('%s/5', GITLAB_NOTES), 200, '{"id": 5}');
    $answer = MergeRequestNote::inEnvironment(gitlabMergeRequest(), $cloud->exchange(), 'MUTATION_GATE_GITLAB_TOKEN')
        ->rechecked(Rechecks::mixed());

    expect($answer)->toEqual($written
        ? Written::to('https://gitlab.example/acme/shop/-/merge_requests/7#note_5')
        : NotWritten::because(CommentStates::NOT_PLANNED))
        ->and(in_array('PUT', array_column($cloud->requests, 'method'), strict: true))->toBe($written);

    if ($written) {
        expect(Decoded::at($cloud->requests[2]['body'], 'body'))
            ->toBe(NoteMarkdown::of(RecheckedMarkdown::comment(Rechecks::mixed(), GITLAB_PIPELINE), GITLAB_PIPELINE));
    }
})->with([
    'its planned state' => [fn(): string => (string) json_encode([gitlabNote(5, 'project_42_bot', PlannedMarkdown::comment(ShardedPlan::planned(2), ''))]), true],
    'the verdict' => [fn(): string => (string) json_encode([gitlabNote(5, 'project_42_bot', Markdown::comment(Verdicts::failing()))]), false],
    'no note of its own' => [fn(): string => (string) json_encode([gitlabNote(5, 'someone', PlannedMarkdown::comment(ShardedPlan::planned(2), ''))]), false],
]);

it('writes no note, and says why, where the job cannot write one', function (Variables $environment, string $why): void {
    $cloud = new Cloud();
    $answer = MergeRequestNote::inEnvironment($environment, $cloud->exchange(), 'MUTATION_GATE_GITLAB_TOKEN')
        ->report(Verdicts::failing());

    expect($answer)->toEqual(NotWritten::because($why))
        ->and($cloud->requests)->toBe([]);
})->with([
    'no merge request' => [fn(): Variables => gitlabMergeRequest(['CI_MERGE_REQUEST_IID' => '']), 'This run is not for a merge request, so there is no note to write.'],
    'a fork\'s pipeline' => [fn(): Variables => gitlabMergeRequest(['CI_MERGE_REQUEST_SOURCE_PROJECT_ID' => '77']), 'A fork\'s merge request pipeline holds none of the project\'s variables, so no note is written.'],
    'no token' => [fn(): Variables => gitlabMergeRequest(['MUTATION_GATE_GITLAB_TOKEN' => '']), 'MUTATION_GATE_GITLAB_TOKEN is not set, so no note is written.'],
]);

it('reads its token from the variable tokenEnv names', function (): void {
    $environment = gitlabMergeRequest(['MUTATION_GATE_GITLAB_TOKEN' => '', 'GATE_NOTES' => 'glpat-other']);
    $cloud = gitlabHolding('[]')->answering(GITLAB_NOTES, 201, '{"id": 3}');

    $named = MergeRequestNote::configured(Configs::options('{"tokenEnv": "GATE_NOTES"}'), $environment, $cloud->exchange());
    $standard = MergeRequestNote::configured(Configs::options('{}'), $environment, new Cloud()->exchange());

    expect($named->report(Verdicts::passing()))->toBeInstanceOf(Written::class)
        ->and($cloud->requests[0]['headers']['authorization'] ?? '')->toBe('Bearer glpat-other')
        ->and($standard->report(Verdicts::passing()))
        ->toEqual(NotWritten::because('MUTATION_GATE_GITLAB_TOKEN is not set, so no note is written.'));
});

it('writes the note of the merge request the trigger job hands a child pipeline, in GitLab.com\'s API where none is named', function (): void {
    $environment = Variables::of([
        'GITLAB_CI' => 'true',
        'CI_PROJECT_ID' => '42',
        'CI_PROJECT_URL' => 'https://gitlab.com/acme/shop',
        GitLabPlan::MERGE_REQUEST => '7',
        'MUTATION_GATE_GITLAB_TOKEN' => 'glpat-secret',
    ]);
    $notes = 'https://gitlab.com/api/v4/projects/42/merge_requests/7/notes';
    $cloud = new Cloud()->answering('https://gitlab.com/api/v4/user', 200, '{"username": "bot"}')
        ->answering(sprintf('%s?sort=asc&order_by=created_at&per_page=100&page=1', $notes), 200, '[]')
        ->answering($notes, 201, '{"id": 4}');

    expect(MergeRequestNote::inEnvironment($environment, $cloud->exchange(), 'MUTATION_GATE_GITLAB_TOKEN')->report(Verdicts::passing()))
        ->toEqual(Written::to('https://gitlab.com/acme/shop/-/merge_requests/7#note_4'));
});

it('says why, naming GitLab by its origin alone and never the token, where GitLab refuses', function (string $refused): void {
    $cloud = gitlabHolding('[]')->answering($refused, 403, '{"message": "403 Forbidden"}')->answering(GITLAB_NOTES, 403, '{"message": "403 Forbidden"}');
    $answer = MergeRequestNote::inEnvironment(gitlabMergeRequest(), $cloud->exchange(), 'MUTATION_GATE_GITLAB_TOKEN')
        ->report(Verdicts::passing());
    $why = $answer instanceof NotWritten ? $answer->why() : '';

    expect($why)->toBe('The merge request note could not be written (https://gitlab.example answered 403: {"message": "403 Forbidden"}).')
        ->and($cloud->requests)->not->toBe([]);
})->with([
    'the user' => [fn(): string => sprintf('%s/user', GITLAB_API)],
    'the notes' => [fn(): string => gitlabNotesPage(1)],
    'the post' => [GITLAB_NOTES],
]);

it('leaves its note, and its planned state, for deliver on a merge request, token or not', function (string $token): void {
    $cloud = new Cloud();
    $note = MergeRequestNote::inEnvironment(
        gitlabMergeRequest(['MUTATION_GATE_GITLAB_TOKEN' => $token]),
        $cloud->exchange(),
        'MUTATION_GATE_GITLAB_TOKEN',
    );

    expect($note->deferred(Verdicts::failing(), Delivery::none()))
        ->toEqual(Delivery::none()->withComment(Markdown::comment(Verdicts::failing())))
        ->and($note->plannedLater(ShardedPlan::planned(2), Delivery::none()))
        ->toEqual(Delivery::none()->withComment(PlannedMarkdown::comment(ShardedPlan::planned(2), GITLAB_PIPELINE)))
        ->and($note->recheckedLater(Rechecks::mixed(), Delivery::none()))
        ->toEqual(Delivery::none()->withCommentOverPlanned(RecheckedMarkdown::comment(Rechecks::mixed(), GITLAB_PIPELINE)))
        ->and($cloud->requests)->toBe([]);
})->with(['with a token' => ['glpat-secret'], 'with none' => ['']]);

it('links no pipeline in the planned state left for deliver where it names none', function (): void {
    $note = MergeRequestNote::inEnvironment(Variables::of(['CI_MERGE_REQUEST_IID' => '7']), new Cloud()->exchange(), 'MUTATION_GATE_GITLAB_TOKEN');

    expect($note->plannedLater(ShardedPlan::planned(2), Delivery::none()))
        ->toEqual(Delivery::none()->withComment(PlannedMarkdown::comment(ShardedPlan::planned(2), '')));
});

it('writes the note of a merge request whose source project is the one it targets', function (): void {
    $cloud = gitlabHolding('[]')->answering(GITLAB_NOTES, 201, '{"id": 6}');
    $answer = MergeRequestNote::inEnvironment(
        gitlabMergeRequest(['CI_MERGE_REQUEST_SOURCE_PROJECT_ID' => '42']),
        $cloud->exchange(),
        'MUTATION_GATE_GITLAB_TOKEN',
    )->report(Verdicts::passing());

    expect($answer)->toEqual(Written::to('https://gitlab.example/acme/shop/-/merge_requests/7#note_6'));
});

it('leaves no note for deliver on a pipeline that is no merge request\'s', function (): void {
    $note = MergeRequestNote::inEnvironment(Variables::of(['GITLAB_CI' => 'true']), new Cloud()->exchange(), 'MUTATION_GATE_GITLAB_TOKEN');

    expect($note->deferred(Verdicts::failing(), Delivery::none()))->toEqual(Delivery::none())
        ->and($note->plannedLater(ShardedPlan::planned(1), Delivery::none()))->toEqual(Delivery::none())
        ->and($note->recheckedLater(Rechecks::mixed(), Delivery::none()))->toEqual(Delivery::none())
        ->and($note->write('text'))->toEqual(NotWritten::because('This run is not for a merge request, so there is no note to write.'))
        ->and($note->writeOverPlanned('text'))->toEqual(NotWritten::because('This run is not for a merge request, so there is no note to write.'));
});

it('reads the notes of a project encoded where its id holds a reserved character', function (): void {
    $cloud = new Cloud()->answering(sprintf('%s/user', GITLAB_API), 200, '{"username": "project_42_bot"}');
    $job = gitlabMergeRequest(['CI_PROJECT_ID' => 'acme/shop', 'CI_MERGE_REQUEST_PROJECT_ID' => 'acme/shop']);
    MergeRequestNote::inEnvironment($job, $cloud->exchange(), 'MUTATION_GATE_GITLAB_TOKEN')->report(Verdicts::failing());

    expect($cloud->requests[1]['url'])
        ->toBe(sprintf('%s/projects/acme%%2Fshop/merge_requests/7/notes?sort=asc&order_by=created_at&per_page=100&page=1', GITLAB_API));
});
