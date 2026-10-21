<script lang="ts">
	import { ui } from '@/lib/ui';
</script>

<a
	href="#contenido"
	class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:rounded focus:bg-white focus:px-3 focus:py-2 focus:shadow"
>
	Saltar al contenido
</a>

<main id="contenido" class="flex min-h-screen flex-col justify-center bg-ink-950 px-6 py-16 text-white">
	<div class="mx-auto flex w-full max-w-xl flex-col gap-5">
		<span class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-600" aria-hidden="true">
			<svg
				width="22"
				height="22"
				viewBox="0 0 24 24"
				fill="none"
				stroke="currentColor"
				stroke-width="2.2"
				stroke-linecap="round"
				stroke-linejoin="round"
				><path d="M9 11l3 3 8-8"></path><path d="M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h9"></path></svg
			>
		</span>
		<h1 class="font-display text-4xl font-extrabold tracking-tight">Plataforma de Assessments</h1>
		<p class="text-lg leading-relaxed text-ink-300">
			Si recibió una invitación, abra el enlace que le enviaron para responder el assessment.
		</p>
		<p class="mt-4">
			<a href="/admin" class={ui.btnPrimary}>Ingreso de administradores</a>
		</p>
	</div>
</main>
