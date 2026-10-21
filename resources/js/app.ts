import '../css/app.css';
import { createInertiaApp } from '@inertiajs/svelte';
import { mount } from 'svelte';

createInertiaApp({
	resolve: (name) => {
		const pages = import.meta.glob('./Pages/**/*.svelte');
		const page = pages[`./Pages/${name}.svelte`];
		if (!page) throw new Error(`Página no encontrada: ${name}`);
		return page() as never;
	},
	setup({ el, App, props }) {
		mount(App, { target: el!, props });
	},
	progress: { color: '#374ef7' }
});
