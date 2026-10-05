<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function is_string;
use function json_encode;

use NightWorksIO\MutationGate\Core\Config\Definition\Text;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * A name a store's options hold that a request's URL carries, as its service
 * allows it. A name a host holds can then end no host, and one a path holds
 * can start no query, so a store's credentials reach only its own service.
 */
enum StoreName: string
{
    /** A Cloud Storage bucket's name as Google allows it, which a URL's path holds as it is. */
    case GcsBucket = '^[a-z0-9][a-z0-9_.-]{1,220}[a-z0-9]$';

    /** A storage account's name as Azure allows it, which the account's host name holds. */
    case AzureAccount = '^[a-z0-9]{3,24}$';

    /** A container's name as Azure allows it: 3 to 63 letters, digits and single hyphens between them. */
    case AzureContainer = '^[a-z0-9](?:[a-z0-9]|-(?=[a-z0-9])){2,62}$';

    /** A region's name, letters and digits in parts joined by single hyphens, which AWS's host name holds. */
    case S3Region = '^[a-z0-9]+(?:-[a-z0-9]+)*$';

    /** The definition's text of such a name. */
    public function text(): Text
    {
        $what = match ($this) {
            self::GcsBucket => 'a Cloud Storage bucket name',
            self::AzureAccount => 'a storage account name',
            self::AzureContainer => 'a container name',
            self::S3Region => 'a region',
        };

        return Text::matching($what, $this->value);
    }

    /** The name an option holds, where it gives one: as the service allows it, or the problem of another. */
    public function in(Options $options, StoreOption $option): string|NotGiven|Problem
    {
        $text = $options->text(Key::of($option->value));
        $name = $this->text();

        return is_string($text) && ! $name->admits($text)
            ? Problem::mismatch($option->value, $name->expected(), json_encode($text, JsonText::FLAGS))
            : $text;
    }
}
