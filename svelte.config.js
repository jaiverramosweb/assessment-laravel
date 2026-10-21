import { vitePreprocess } from '@sveltejs/vite-plugin-svelte';

export default {
	preprocess: vitePreprocess(),
	vitePlugin: {
		// Runes mode for the app's own components only: Inertia's components use legacy syntax.
		dynamicCompileOptions({ filename }) {
			if (!filename.includes('node_modules')) return { runes: true };
		}
	}
};
