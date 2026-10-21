import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import { svelte } from '@sveltejs/vite-plugin-svelte';
import { fileURLToPath } from 'node:url';

export default defineConfig({
	plugins: [
		laravel({
			input: ['resources/js/app.ts'],
			refresh: true
		}),
		svelte(),
		tailwindcss()
	],
	resolve: {
		alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) }
	},
	server: {
		watch: {
			ignored: ['**/storage/framework/views/**']
		}
	},
	test: {
		include: ['resources/js/**/*.spec.ts']
	}
});
