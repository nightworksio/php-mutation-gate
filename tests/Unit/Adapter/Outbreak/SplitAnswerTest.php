<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Outbreak\SplitAnswer;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Written;

it('says how many maps the binary wrote, why it declined, or why it did not answer', function (Node|NotWritten $said, Written|NotWritten $answer): void {
    expect(SplitAnswer::of($said, Path::of('map.json.gz')))->toEqual($answer);
})->with([
    'written' => [fn(): Node => Node::decode('{"written":2}'), fn(): Written => Written::noting('map.json.gz', 'Outbreak wrote 2 coverage maps from map.json.gz.')],
    'declined' => [fn(): Node => Node::decode('{"declined":"no map"}'), fn(): NotWritten => NotWritten::because('Outbreak declined to split the map: no map')],
    'not run' => [fn(): NotWritten => NotWritten::because('it timed out'), fn(): NotWritten => NotWritten::because('Outbreak did not split the map: it timed out')],
]);
