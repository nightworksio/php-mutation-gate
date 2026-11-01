import svg from '../../../.docs/brand/php-mutation-gate-favicon.svg?raw';
import png32 from '../../../.docs/brand/php-mutation-gate-favicon-32.png?inline';
import png180 from '../../../.docs/brand/php-mutation-gate-favicon-180.png?inline';
import png512 from '../../../.docs/brand/php-mutation-gate-favicon-512.png?inline';

const ICONS = {
    'favicon.svg': ['image/svg+xml', svg],
    'favicon-32.png': ['image/png', png32],
    'favicon-180.png': ['image/png', png180],
    'favicon-512.png': ['image/png', png512],
};

function bytes(content) {
    return content.startsWith('data:') ? Buffer.from(content.slice(content.indexOf(',') + 1), 'base64') : content;
}

/** The site's icons, each the mark padded to a square (.docs/brand). */
export function getStaticPaths() {
    return Object.keys(ICONS).map((icon) => ({ params: { icon } }));
}

export function GET({ params }) {
    const [type, content] = ICONS[params.icon];

    return new Response(bytes(content), { headers: { 'Content-Type': type } });
}
