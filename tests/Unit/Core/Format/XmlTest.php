<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\Xml;

it('reads XML it did not write fetching nothing and printing nothing, so a file that is not XML reads as none', function (): void {
    $document = new DOMDocument();

    expect(Xml::QUIET)->toBe(LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)
        ->and($document->loadXML('<not xml', Xml::QUIET))->toBeFalse();
});
