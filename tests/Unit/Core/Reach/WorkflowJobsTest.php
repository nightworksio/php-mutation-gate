<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Reach\WorkflowJobs;
use NightWorksIO\MutationGate\Tests\Support\Tree;

const GATED_WORKFLOW = <<<'YAML'
    name: ci
    on: pull_request
    env:
      PHP: '8.5'
    jobs:
      lint:
        runs-on: ubuntu-latest
        steps:
          - run: composer lint
      gate:
        name: mutation
        needs:
          - lint
        if: >-
          github.event_name == 'pull_request'
        runs-on: ubuntu-latest
        steps:
          - uses: nightworksio/php-mutation-gate@1111111111111111111111111111111111111111
    permissions:
      contents: read
    YAML;

it('keeps the keys every job shares and the job that runs the gate, without its own name, needs and if', function (): void {
    expect(WorkflowJobs::running(GATED_WORKFLOW))->toBe(<<<'YAML'
        on: pull_request
        env:
          PHP: '8.5'
        jobs:
          gate:
            runs-on: ubuntu-latest
            steps:
              - uses: nightworksio/php-mutation-gate@1111111111111111111111111111111111111111
        permissions:
          contents: read
        YAML);
});

it('takes a job for one that runs the gate where it uses its action or reusable workflow, or runs it to judge', function (string $line, bool $runs): void {
    $workflow = sprintf("on: push\njobs:\n  a:\n    steps:\n      - run: composer lint\n  b:\n    steps:\n      - %s\n", $line);
    $read = WorkflowJobs::running($workflow);

    expect($runs ? $read : $read instanceof NotGiven)->toBe($runs ? sprintf("on: push\njobs:\n  b:\n    steps:\n      - %s\n", $line) : true);
})->with([
    'the action' => ['uses: nightworksio/php-mutation-gate@v0', true],
    'the reusable workflow' => ['uses: nightworksio/php-mutation-gate/.github/workflows/mutation-gate.yml@v0', true],
    'this repository\'s reusable workflow' => ['uses: ./.github/workflows/mutation-gate.yml', true],
    'a run' => ['run: vendor/bin/mutation-gate run --budget=15m', true],
    'the default run' => ['run: vendor/bin/mutation-gate', true],
    'options alone' => ['run: vendor/bin/mutation-gate --ci', true],
    'a plan through a quoted path' => ['run: \'"${GATE}/bin/mutation-gate" plan\'', true],
    'a verdict' => ['run: php bin/mutation-gate verdict --plan=plan.json', true],
    'the survivors' => ['run: php bin/mutation-gate survivors', true],
    'the script init adds' => ['run: composer mutate -- --budget=15m', true],
    'the coverage map, which judges nothing' => ['run: php bin/mutation-gate coverage --into=map', false],
    'a delivery' => ['run: php bin/mutation-gate deliver', false],
    'the gate\'s directory' => ['run: mkdir -p .mutation-gate/ran', false],
]);

it('reads whole a definition with no jobs, none that runs the gate, or a job it cannot bound', function (string $workflow): void {
    expect(WorkflowJobs::running($workflow))->toBeInstanceOf(NotGiven::class);
})->with([
    'no jobs' => ["stages: [test]\nmutation:\n  script: vendor/bin/mutation-gate\n"],
    'no job that runs the gate' => ["on: push\njobs:\n  lint:\n    steps:\n      - run: composer lint\n"],
    'a gate named only in a comment' => ["on: push\njobs:\n  lint:\n    steps:\n      # - uses: nightworksio/php-mutation-gate@v0\n      - run: composer lint\n"],
    'a job id it cannot read' => ["on: push\njobs:\n  \"a job\":\n    steps:\n      - uses: nightworksio/php-mutation-gate@v0\n"],
    'a line at a job\'s indentation it cannot place' => ["on: push\njobs:\n  gate:\n    steps:\n      - uses: nightworksio/php-mutation-gate@v0\n flow: [a]\n"],
]);

it('keeps a gate job\'s keys besides its own name, if and needs, and a step\'s own name and if', function (): void {
    $workflow = "on: push\njobs:\n  gate:\n    if: always()\n    timeout-minutes: 30\n    steps:\n      - name: the gate\n        if: always()\n        run: vendor/bin/mutation-gate\n";

    expect(WorkflowJobs::running($workflow))
        ->toBe("on: push\njobs:\n  gate:\n    timeout-minutes: 30\n    steps:\n      - name: the gate\n        if: always()\n        run: vendor/bin/mutation-gate\n");
});

it('keeps a blank line a workflow opens with', function (): void {
    expect(WorkflowJobs::running("\non: push\njobs:\n  gate:\n    steps:\n      - run: vendor/bin/mutation-gate\n"))
        ->toBe("\non: push\njobs:\n  gate:\n    steps:\n      - run: vendor/bin/mutation-gate\n");
});

it('reads each GitHub workflow init writes by the job that runs the gate', function (string $template): void {
    expect(WorkflowJobs::running((string) file_get_contents(Tree::at(sprintf('resources/ci/github/%s.yml', $template)))))->toBeString();
})->with(['single', 'sharded']);

it('leaves out the workflow\'s own name, run-name and concurrency, which say what its runs are called and whether one waits', function (): void {
    $workflow = <<<'YAML'
        name: ci
        run-name: ci of ${{ github.ref }}
        concurrency:
          group: ci-${{ github.ref }}
          cancel-in-progress: true
        env:
          PHP: '8.5'
        jobs:
          gate:
            steps:
              - run: vendor/bin/mutation-gate
        YAML;

    expect(WorkflowJobs::running($workflow))
        ->toBe("env:\n  PHP: '8.5'\njobs:\n  gate:\n    steps:\n      - run: vendor/bin/mutation-gate");
});
