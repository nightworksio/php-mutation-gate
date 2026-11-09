<?php

declare(strict_types=1);

/*
 * One job of the claim race (ADR-0134): claims every item of one race with
 * the gate's own claims, and writes the items it won, one a line, to a file.
 *
 * Usage: php .github/scripts/claim_race.php <items> <job> <won-file>
 */

use NightWorksIO\MutationGate\Adapter\GitHub\ArtifactClaims;
use NightWorksIO\MutationGate\Adapter\Http\HttpExchange;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\ShardPull;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use Symfony\Component\HttpClient\HttpClient;

require __DIR__ . '/../../vendor/autoload.php';

[, $items, $job, $wonFile] = $argv + [null, '10', '1', 'won.txt'];
$claims = ArtifactClaims::of(
    Variables::of(getenv()),
    HttpExchange::over(HttpClient::create()),
    ShardPull::Claim,
    Digest::sha256Of(sprintf('claim-race-%s', getenv('GITHUB_RUN_ID'))),
    ShardId::of((int) $job),
);

if ($claims instanceof CannotJudge) {
    fwrite(STDERR, $claims->why() . "\n");
    exit(1);
}

$won = [];

for ($item = 1; $item <= (int) $items; $item++) {
    $taken = $claims->take(ShardId::of($item));

    if ($taken instanceof CannotJudge) {
        fwrite(STDERR, sprintf("Item %d: %s\n", $item, $taken->why()));
        exit(1);
    }

    if ($taken) {
        $won[] = $item;
    }
}

file_put_contents($wonFile, implode("\n", $won) . ($won === [] ? '' : "\n"));
printf("Job %d won %d of %d items.\n", (int) $job, count($won), (int) $items);
