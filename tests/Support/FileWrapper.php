<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function fclose;
use function fopen;
use function is_object;
use function is_resource;
use function stream_get_meta_data;
use function stream_wrapper_register;
use function stream_wrapper_restore;
use function stream_wrapper_unregister;

/**
 * The stream wrapper that serves files in this process, kept before a test
 * puts its own in their place and put back after it. In a mutant's own run
 * that is Pest's override serving the mutant, which PHP's own wrapper in its
 * place would leave unserved for every class loaded after.
 */
final class FileWrapper
{
    private const string PROTOCOL = 'file';

    /** How a stream's metadata names a wrapper of PHP code, whose object it holds beside. */
    private const string USER_SPACE = 'user-space';

    /** The class of the wrapper kept, or none where PHP's own serves files. */
    private static string $kept = '';

    /** Keeps the wrapper serving files now. */
    public static function keep(): void
    {
        self::$kept = self::serving();
    }

    /** Whether a wrapper other than the kept one serves files now. */
    public static function changed(): bool
    {
        return self::serving() !== self::$kept;
    }

    /** Puts the kept wrapper back in place of whatever serves files now. */
    public static function restore(): void
    {
        if (! self::changed()) {
            return;
        }

        if (self::serving() !== '') {
            stream_wrapper_restore(self::PROTOCOL);
        }

        if (self::$kept !== '') {
            stream_wrapper_unregister(self::PROTOCOL);
            stream_wrapper_register(self::PROTOCOL, self::$kept);
        }
    }

    /** The class of the wrapper serving files now, or none where PHP's own does. */
    private static function serving(): string
    {
        $handle = fopen(__FILE__, 'rb');

        if (! is_resource($handle)) {
            return '';
        }

        $meta = stream_get_meta_data($handle);
        fclose($handle);
        $wrapper = $meta['wrapper_type'] === self::USER_SPACE ? $meta['wrapper_data'] : '';

        return is_object($wrapper) ? $wrapper::class : '';
    }
}
