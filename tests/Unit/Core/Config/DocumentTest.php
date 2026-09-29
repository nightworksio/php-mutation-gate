<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Document;

it('holds a config as JSON text', function (): void {
    $document = Document::ofJson('{"runner": "pest"}');

    expect($document instanceof Document ? $document->json() : '')->toBe('{"runner": "pest"}');
});

it('cannot judge with text that is not JSON', function (): void {
    expect(Document::ofJson('{"runner": '))->toEqual(CannotJudge::because('A config was read into text that is not JSON: Syntax error.'));
});
