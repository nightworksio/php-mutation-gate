<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Reach\AsItRuns;

$one = str_repeat('a', 40);
$two = str_repeat('b', 40);

it('runs alike where nothing changed', function (): void {
    $same = Contents::of("jobs:\n  gate:\n    steps:\n      - run: composer test\n");

    expect(AsItRuns::alike($same, $same))->toBeTrue();
});

it('runs otherwise where another line changed', function (): void {
    expect(AsItRuns::alike(
        Contents::of("steps:\n  - run: composer test\n"),
        Contents::of("steps:\n  - run: composer test:all\n"),
    ))->toBeFalse();
});

it('runs otherwise where the commit an action is pinned at moved, whichever action it is', function (string $action) use ($one, $two): void {
    expect(AsItRuns::alike(
        Contents::of(sprintf("steps:\n  - uses: %s@%s # v1.0.0\n", $action, $one)),
        Contents::of(sprintf("steps:\n  - uses: %s@%s # v1.0.0\n", $action, $two)),
    ))->toBeFalse();
})->with([
    'the gate' => ['nightworksio/php-mutation-gate'],
    'the gate\'s workflow' => ['nightworksio/php-mutation-gate/.github/workflows/mutation-gate.yml'],
    'the runtime' => ['shivammathur/setup-php'],
    'a checkout' => ['actions/checkout'],
]);

it('reads a definition without its comment lines, and runs alike where only those changed', function (): void {
    $before = Contents::of("# The gate.\njobs:\n  gate:\n    steps:\n      - run: composer test # unit");
    $after = Contents::of("jobs:\n  # One job.\n  gate:\n    steps:\n# The suite.\n      - run: composer test # unit");

    expect(AsItRuns::text($before))->toBe("jobs:\n  gate:\n    steps:\n      - run: composer test # unit")
        ->and(AsItRuns::alike($before, $after))->toBeTrue()
        ->and(AsItRuns::alike($before, Contents::of("jobs:\n  gate:\n    steps:\n      - run: composer test")))->toBeFalse();
});

it('reads a block scalar as it is written, and runs alike where only the comment lines around it changed', function (): void {
    $before = Contents::of("steps:\n  - run: |\n      composer test\n\n      # data\n  - run: composer gate");
    $after = Contents::of("steps:\n  # The suite.\n  - run: |\n      composer test\n\n      # data\n  # The gate.\n  - run: composer gate");

    expect(AsItRuns::text($before))->toBe("steps:\n  - run: |\n      composer test\n\n      # data\n  - run: composer gate")
        ->and(AsItRuns::alike($before, $after))->toBeTrue();
});

it('runs otherwise where a line it would leave out elsewhere is part of a value', function (string $before, string $after): void {
    expect(AsItRuns::alike(Contents::of($before), Contents::of($after)))->toBeFalse();
})->with([
    'a comment line in a literal block' => [
        "steps:\n  - run: |\n      cat > gate.json <<EOF\n      {}\n      EOF\n",
        "steps:\n  - run: |\n      cat > gate.json <<EOF\n      # {}\n      {}\n      EOF\n",
    ],
    'a comment line in a folded block' => [
        "steps:\n  - run: >\n      vendor/bin/mutation-gate\n      --threshold=100\n",
        "steps:\n  - run: >\n      vendor/bin/mutation-gate\n      #\n      --threshold=100\n",
    ],
    'a comment line in a block under with' => [
        "steps:\n  - uses: actions/github-script\n    with:\n      script: |\n        run()\n",
        "steps:\n  - uses: actions/github-script\n    with:\n      script: |\n        # run()\n        run()\n",
    ],
    'a blank line in a literal block' => [
        "steps:\n  - run: |\n      vendor/bin/mutation-gate \\\n        --full\n",
        "steps:\n  - run: |\n      vendor/bin/mutation-gate \\\n\n        --full\n",
    ],
    'a blank line in a folded block' => [
        "steps:\n  - run: >-\n      vendor/bin/mutation-gate\n      --full\n",
        "steps:\n  - run: >-\n      vendor/bin/mutation-gate\n\n      --full\n",
    ],
    'a blank line between nodes' => ["jobs:\n  gate: {}\n", "jobs:\n\n  gate: {}\n"],
    'a blank line after a block' => [
        "steps:\n  - run: |\n      composer test\n  - run: composer gate\n",
        "steps:\n  - run: |\n      composer test\n\n  - run: composer gate\n",
    ],
    'a comment line under a value it could carry on' => [
        "steps:\n  - run: composer gate\n",
        "steps:\n  - run: composer gate\n        # --full\n",
    ],
    'a comment line in a folded block with an indentation indicator' => [
        "steps:\n  - run: >2-\n      vendor/bin/mutation-gate\n",
        "steps:\n  - run: >2-\n      vendor/bin/mutation-gate\n      # --full\n",
    ],
    'a document marker in a block' => [
        "steps:\n  - run: |\n      cat <<EOF\n      ---\n      EOF\n",
        "steps:\n  - run: |\n      cat <<EOF\n      ...\n      EOF\n",
    ],
    'a comment after a value' => ["steps:\n  - run: composer gate # one\n", "steps:\n  - run: composer gate # two\n"],
    'a quoted key' => ["\"on\": push\n", "\"on\": pull_request\n"],
    'a blank line a block keeps at its end' => [
        "steps:\n  - run: |+\n      composer test\n  - run: composer gate\n",
        "steps:\n  - run: |+\n      composer test\n\n  - run: composer gate\n",
    ],
    'a blank line in a plain value over lines' => [
        "steps:\n  - run: vendor/bin/mutation-gate\n      --full\n",
        "steps:\n  - run: vendor/bin/mutation-gate\n\n      --full\n",
    ],
    'a blank line in a quoted value over lines' => [
        "steps:\n  - run: \"vendor/bin/mutation-gate\n      --full\"\n",
        "steps:\n  - run: \"vendor/bin/mutation-gate\n\n      --full\"\n",
    ],
    'a comment line in a single-quoted value over lines' => [
        "steps:\n  - run: 'vendor/bin/mutation-gate\n      --full'\n",
        "steps:\n  - run: 'vendor/bin/mutation-gate\n      #\n      --full'\n",
    ],
    'a blank line in a list over lines' => [
        "on:\n  push:\n    branches: [main,\n      \"release\"]\n",
        "on:\n  push:\n    branches: [main,\n\n      \"release\"]\n",
    ],
    'a pin in a block' => [
        sprintf("steps:\n  - run: |\n      cat > step.yml <<EOF\n      uses: a/b@%s\n      EOF\n", str_repeat('a', 40)),
        sprintf("steps:\n  - run: |\n      cat > step.yml <<EOF\n      uses: a/b@%s\n      EOF\n", str_repeat('b', 40)),
    ],
]);

it('leaves nothing out of a definition it cannot prove it reads as its runner does', function (string $definition): void {
    $commented = sprintf("# The gate.\n%s\n", $definition);

    expect(AsItRuns::text(Contents::of($commented)))->toBe($commented)
        ->and(AsItRuns::alike(Contents::of($commented), Contents::of($definition)))->toBeFalse();
})->with([
    'an anchor' => ["defaults: &php\n  php: '8.4'\njobs:\n  gate: *php"],
    'an alias' => ["jobs:\n  gate:\n    env: *php"],
    'a merge key' => ["jobs:\n  gate:\n    <<: *php"],
    'a tag' => ["jobs:\n  gate:\n    timeout-minutes: !!int 30"],
    'a tag on a block' => ["steps:\n  - run: !!str |\n      composer test"],
    'a second document' => ["jobs: {}\n---\njobs: {}"],
    'a directive' => ["%YAML 1.2\n---\njobs: {}"],
    'a complex key' => ["jobs:\n  ? gate\n  : {}"],
    'a value on the line after its key' => ["steps:\n  - run:\n      composer test"],
    'a tab in an indentation' => ["jobs:\n\tgate: {}"],
    'a quoted value it cannot close' => ["steps:\n  - run: \"composer \\\""],
    'a list it cannot close' => ["on: [push,"],
    'a block header it cannot read' => ["steps:\n  - run: |x\n      composer test"],
    'a line between a value and its sibling' => ["steps:\n  - run: composer test\n   if: always()"],
    'a flow mapping over lines' => ["jobs: {gate: {},\n  other: {}}"],
    'a nested node on its key\'s line' => ["jobs:\n  gate: - run: composer gate"],
    'a complex key in a value' => ["jobs:\n  gate: ? run\n    : composer gate"],
    'a carriage return and a line feed' => ["jobs:\r\n  gate: {}"],
    'a lone carriage return' => ["jobs:\r  gate: {}"],
    'a byte order mark' => ["\u{FEFF}jobs:\n  gate: {}"],
    'a tab in a block' => ["steps:\n  - run: |\n      \tmake gate"],
    'a next line character' => ["jobs:\u{85}  gate: {}"],
    'a line separator' => ["jobs:\u{2028}  gate: {}"],
    'a paragraph separator' => ["jobs:\u{2029}  gate: {}"],
    'a form feed' => ["jobs:\f\n  gate: {}"],
    'a document start at the start of a line in a block' => ["steps:\n  - run: |\n      cat <<EOF\n---\n      EOF"],
    'a document end' => ["jobs: {}\n..."],
]);

it('runs otherwise where a key is written twice or an expression changed', function (string $before, string $after): void {
    expect(AsItRuns::alike(Contents::of($before), Contents::of($after)))->toBeFalse();
})->with([
    'a key written twice' => ["steps:\n  - run: composer gate\n", "steps:\n  - run: composer gate\n    run: composer test\n"],
    'an expression' => ["steps:\n  - run: vendor/bin/mutation-gate \${{ env.ARGS }}\n", "steps:\n  - run: vendor/bin/mutation-gate \${{ env.MORE }}\n"],
]);
