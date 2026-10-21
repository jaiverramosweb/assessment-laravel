<script lang="ts">
	import ClientLogo from '@/components/ClientLogo.svelte';

	let { title, logoUrl, themeStyle }: { title: string; logoUrl: string | null; themeStyle: string | null } =
		$props();
</script>

<svelte:head>
	<title>Entrega registrada · {title}</title>
	<meta name="robots" content="noindex" />
</svelte:head>

<!-- style: colores del cliente (variables de Tailwind), calculados en el servidor. -->
<main
	class="mx-auto flex min-h-screen max-w-lg flex-col items-center justify-center gap-7 px-6 py-12 text-center"
	style={themeStyle}
>
	{#if logoUrl}
		<span class="rounded-xl ring-1 ring-slate-200"><ClientLogo src={logoUrl} /></span>
	{/if}
	<div
		class="flex h-22 w-22 items-center justify-center rounded-full bg-brand-50"
		aria-hidden="true"
	>
		<div class="flex h-15 w-15 items-center justify-center rounded-full bg-brand-600 text-white">
			<svg
				width="30"
				height="30"
				viewBox="0 0 24 24"
				fill="none"
				stroke="currentColor"
				stroke-width="2.6"
				stroke-linecap="round"
				stroke-linejoin="round"><path d="M5 12l5 5 9-10"></path></svg
			>
		</div>
	</div>
	<div class="flex flex-col gap-3">
		<h1 class="font-display text-3xl leading-tight font-extrabold">
			¡Gracias! Sus respuestas fueron entregadas
		</h1>
		<p class="text-[15px] leading-relaxed text-slate-700">
			Su entrega de <strong>{title}</strong> quedó registrada. Ya puede cerrar esta ventana.
		</p>
	</div>
	<p class="text-xs leading-relaxed text-slate-600">
		Solo se permite una entrega por persona. Si tiene dudas, contacte a quien le envió la
		invitación.
	</p>
</main>
