<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\Xml;
use NightWorksIO\MutationGate\Core\NotGiven;

it('reads XML it did not write fetching nothing and printing nothing, so a file that is not XML reads as none', function (): void {
    $document = new DOMDocument();

    expect(Xml::QUIET)->toBe(LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)
        ->and($document->loadXML('<not xml', Xml::QUIET))->toBeFalse();
});

it('reads the document a text holds, and none where there is no text or it is not XML', function (): void {
    $document = Xml::document('<a><b/></a>');

    expect($document instanceof DOMDocument ? $document->getElementsByTagName('b')->length : $document)->toBe(1)
        ->and(Xml::document('<not xml'))->toEqual(NotGiven::value())
        ->and(Xml::document(''))->toEqual(NotGiven::value())
        ->and(Xml::document(text: false))->toEqual(NotGiven::value());
});
