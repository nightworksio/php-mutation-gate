import { defineCollection } from 'astro:content';
import { docsSchema } from '@astrojs/starlight/schema';
import { pages } from './pages.mjs';

export const collections = {
    docs: defineCollection({ loader: pages(), schema: docsSchema() }),
};
