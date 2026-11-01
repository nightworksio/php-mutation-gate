import sharp from 'sharp';
import card from '../../../.docs/brand/php-mutation-gate-social.svg?raw';

/** The social card, rendered to the PNG an Open Graph image must be. */
export async function GET() {
    return new Response(await sharp(Buffer.from(card)).png().toBuffer(), {
        headers: { 'Content-Type': 'image/png' },
    });
}
