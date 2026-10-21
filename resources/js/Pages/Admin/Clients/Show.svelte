<script lang="ts">
	import { Link, page, router, useForm } from '@inertiajs/svelte';
	import AdminLayout from '@/Layouts/AdminLayout.svelte';
	import BrandEditor from '@/components/BrandEditor.svelte';
	import StatusBadge from '@/components/StatusBadge.svelte';
	import type { AssessmentStatus } from '@/lib/constants';
	import { formatShortDate } from '@/lib/format';
	import { ui } from '@/lib/ui';
	import type { SharedProps } from '@/types';

	type ClientData = {
		id: string;
		name: string;
		themePrimary: string | null;
		themeAccent: string | null;
		logoUrl: string | null;
	};
	type ClientAssessment = {
		id: string;
		title: string;
		status: AssessmentStatus;
		startsAt: number;
		endsAt: number;
		timezone: string;
		participantCount: number;
		submittedCount: number;
	};

	let { client: c, assessments }: { client: ClientData; assessments: ClientAssessment[] } = $props();

	const shared = $derived($page.props as unknown as SharedProps);
	const created = $derived(new URL($page.url, 'http://x').searchParams.get('creado') === '1');
	const base = $derived(`/admin/clientes/${c.id}`);

	// svelte-ignore state_referenced_locally
	const nameForm = useForm({ name: c.name });

	function rename(event: SubmitEvent) {
		event.preventDefault();
		$nameForm.post(`${base}/nombre`, { preserveState: true, preserveScroll: true });
	}

	function remove(event: SubmitEvent) {
		event.preventDefault();
		if (!confirm(`¿Eliminar el cliente "${c.name}"?`)) return;
		router.delete(base, { preserveState: true, preserveScroll: true });
	}
</script>

<svelte:head>
	<title>{c.name} · Clientes</title>
</svelte:head>

<AdminLayout>
	<div class="space-y-6">
		<div class="flex flex-col gap-1.5">
			<nav class="text-sm text-slate-500">
				<Link href="/admin/clientes" class="hover:underline">Clientes</Link> / {c.name}
			</nav>
			<div class="flex flex-wrap items-center justify-between gap-3">
				<h1 class={ui.pageTitle}>{c.name}</h1>
				<Link href="{base}/informe" class={ui.btnPrimary}>Ver informe general</Link>
			</div>
		</div>

		{#if created}
			<p class={ui.alertSuccess} role="status">
				Cliente creado. Suba su logo y elija sus colores; luego cree sus assessments.
			</p>
		{/if}

		<!-- Nombre -->
		<section class={ui.card} aria-labelledby="nombre-titulo">
			<h2 id="nombre-titulo" class={ui.sectionTitle}>Nombre</h2>
			<form method="POST" onsubmit={rename} class="mt-3 flex flex-wrap items-end gap-3">
				<div class="min-w-64 flex-1">
					<label for="name" class="sr-only">Nombre del cliente</label>
					<input
						id="name"
						name="name"
						required
						maxlength="120"
						bind:value={$nameForm.name}
						aria-invalid={!!$nameForm.errors.name}
						class={ui.input}
					/>
					{#if $nameForm.errors.name}<p class={ui.fieldError} role="alert">{$nameForm.errors.name}</p>{/if}
				</div>
				<button class={ui.btnSecondary} disabled={$nameForm.processing}>Guardar nombre</button>
			</form>
			{#if shared.flash.success === 'Nombre guardado.'}
				<p class="mt-3 {ui.alertSuccess}" role="status">Nombre guardado.</p>
			{/if}
		</section>

		<BrandEditor
			action={base}
			logoUrl={c.logoUrl}
			themePrimary={c.themePrimary}
			themeAccent={c.themeAccent}
			previewTitle="Assessment de {c.name}"
		/>

		<!-- Assessments del cliente -->
		<section class={ui.card} aria-labelledby="assessments-titulo">
			<div class="flex flex-wrap items-center justify-between gap-3">
				<h2 id="assessments-titulo" class={ui.sectionTitle}>Assessments</h2>
				<Link href="/admin/assessments/nuevo?cliente={c.id}" class={ui.btnPrimary}>
					Nuevo assessment para {c.name}
				</Link>
			</div>
			{#if assessments.length === 0}
				<p class="mt-3 text-sm text-slate-600">Este cliente todavía no tiene assessments.</p>
			{:else}
				<ul class="mt-4 divide-y divide-slate-100">
					{#each assessments as a (a.id)}
						<li class="flex flex-wrap items-center gap-x-4 gap-y-1 py-3">
							<Link
								href="/admin/assessments/{a.id}"
								class="min-w-0 flex-1 font-medium text-ink-950 hover:underline">{a.title}</Link
							>
							<StatusBadge status={a.status} />
							<span class="text-sm text-slate-600">
								{formatShortDate(a.startsAt, a.timezone)} – {formatShortDate(a.endsAt, a.timezone)}
							</span>
							<span class="text-sm text-slate-600">
								{a.submittedCount} de {a.participantCount} entregas
							</span>
						</li>
					{/each}
				</ul>
			{/if}
		</section>

		<!-- Eliminar -->
		<section class="rounded-2xl border border-red-200 bg-white p-6" aria-labelledby="eliminar-titulo">
			<h2 id="eliminar-titulo" class="text-base font-semibold text-red-800">Eliminar cliente</h2>
			{#if assessments.length}
				<p class="mt-1 text-sm text-slate-700">
					Solo se puede eliminar un cliente sin assessments. Elimine sus assessments o asígnelos a
					otro cliente primero.
				</p>
			{:else}
				<p class="mt-1 text-sm text-slate-700">Se borrarán el cliente, su logo y sus colores.</p>
				<form method="POST" onsubmit={remove} class="mt-3">
					<button class={ui.btnDanger}>Eliminar cliente</button>
				</form>
			{/if}
			{#if shared.errors.delete}<p class="mt-2 {ui.fieldError}" role="alert">{shared.errors.delete}</p>{/if}
		</section>
	</div>
</AdminLayout>
