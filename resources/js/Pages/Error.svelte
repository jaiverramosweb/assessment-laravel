<script lang="ts">
	let { status, message }: { status: number; message: string | null } = $props();

	const titles: Record<number, string> = {
		403: 'Acceso no permitido',
		404: 'Página no encontrada',
		429: 'Demasiados intentos',
		500: 'Error del servidor',
		503: 'Servicio no disponible'
	};
</script>

<svelte:head>
	<title>{titles[status] ?? 'Error'}</title>
</svelte:head>

<main class="mx-auto flex min-h-screen max-w-xl flex-col justify-center px-6 py-16">
	<p class="text-sm font-semibold text-brand-600">Error {status}</p>
	<h1 class="mt-2 text-2xl font-semibold">{titles[status] ?? 'Algo salió mal'}</h1>
	{#if message && status !== 404}
		<p class="mt-3 text-slate-600">{message}</p>
	{/if}
	<p class="mt-8">
		<a href="/" class="text-brand-600 underline hover:text-brand-500">Volver al inicio</a>
	</p>
</main>
