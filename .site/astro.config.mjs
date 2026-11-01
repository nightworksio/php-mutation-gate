import { unified } from '@astrojs/markdown-remark';
import starlight from '@astrojs/starlight';
import mermaid from 'astro-mermaid';
import { defineConfig } from 'astro/config';
import { links } from './src/links.mjs';
import { dropTitle, rewriteLinks } from './src/remark.mjs';
import { group } from './src/sidebar.mjs';
import { BASE, REPOSITORY, SITE } from './src/where.mjs';

export default defineConfig({
    site: SITE,
    base: BASE,
    trailingSlash: 'always',
    markdown: {
        processor: unified({ remarkPlugins: [dropTitle, rewriteLinks] }),
    },
    vite: {
        server: { fs: { allow: ['..'] } },
    },
    integrations: [
        mermaid({
            theme: 'base',
            autoTheme: false,
            enableLog: false,
            mermaidConfig: {
                themeVariables: {
                    background: '#12101f',
                    primaryColor: '#36314b',
                    primaryTextColor: '#f4f1c9',
                    primaryBorderColor: '#39d36b',
                    secondaryColor: '#124a2c',
                    tertiaryColor: '#2b2638',
                    lineColor: '#9b9bb0',
                    textColor: '#f2eedc',
                },
            },
        }),
        starlight({
            title: 'php-mutation-gate',
            logo: { src: '../.docs/brand/php-mutation-gate-mark.svg', alt: 'php-mutation-gate' },
            favicon: '/favicon.svg',
            head: [
                { tag: 'link', attrs: { rel: 'icon', type: 'image/png', sizes: '32x32', href: `${BASE}/favicon-32.png` } },
                { tag: 'link', attrs: { rel: 'apple-touch-icon', sizes: '180x180', href: `${BASE}/favicon-180.png` } },
                { tag: 'meta', attrs: { property: 'og:image:alt', content: 'php-mutation-gate: a pixel-art gate with the PHP elephant standing in its arch' } },
                { tag: 'meta', attrs: { property: 'og:image', content: `${SITE}${BASE}/og.png` } },
                { tag: 'meta', attrs: { property: 'og:image:width', content: '1280' } },
                { tag: 'meta', attrs: { property: 'og:image:height', content: '640' } },
                { tag: 'meta', attrs: { name: 'twitter:card', content: 'summary_large_image' } },
            ],
            social: [{ icon: 'github', label: 'GitHub', href: REPOSITORY }],
            customCss: ['./src/theme.css'],
            sidebar: [
                group('Guide', 'guide'),
                group('Reference', 'reference'),
                group('Design', 'decisions', true),
            ],
        }),
        links(BASE),
    ],
});
