<script lang="ts">
	import { Link, page, useForm } from '@inertiajs/svelte';
	import AdminLayout from '@/Layouts/AdminLayout.svelte';
	import { DEFAULT_ACCENT, DEFAULT_PRIMARY } from '@/lib/theme';
	import { ui } from '@/lib/ui';

	type ClientRow = {
		id: string;
		name: string;
		logoUrl: string | null;
		themePrimary: string | null;
		themeAccent: string | null;
		assessmentCount: number;
	};

	let { clients }: { clients: ClientRow[] } = $props();

	const deleted = $derived(new URL($page.url, 'http://x').searchParams.get('eliminado') === '1');
	const form = useForm({ name: '' });

	function create(event: SubmitEvent) {
		event.preventDefault();
		// The typed name stays in the field when it is rejected.
		$form.post('/admin/clientes', { preserveState: true, preserveScroll: true });
	}
</script>

<svelte:head>
	<title>Clientes · Administración</title>
</svelte:head>

<AdminLayout>
	<div class="flex flex-col gap-8">
		<div class="flex flex-col gap-1.5">
			<p class={ui.eyebrow}>Panel de administración</p>
			<h1 class={ui.pageTitle}>Clientes</h1>
			<p class="max-w-2xl text-sm text-slate-600">
				Cada cliente tiene su logo y sus colores. Todos sus assessments los usan en las pantallas del
				participante y en los informes.
			</p>
		</div>

		{#if deleted}
			<p class={ui.alertSuccess} role="status">Cliente eliminado.</p>
		{/if}

		<form method="POST" onsubmit={create} class="{ui.card} flex flex-wrap items-end gap-3">
			<div class="min-w-64 flex-1">
				<label for="name" class={ui.label}>Nuevo cliente</label>
				<input
					id="name"
					name="name"
					required
					maxlength="120"
					placeholder="Nombre de la empresa"
					bind:value={$form.name}
					aria-invalid={!!$form.errors.name}
					class={ui.input}
				/>
				{#if $form.errors.name}<p class={ui.fieldError} role="alert">{$form.errors.name}</p>{/if}
			</div>
			<button class={ui.btnPrimary} disabled={$form.processing}>Crear cliente</button>
		</form>

		{#if clients.length === 0}
			<p class="{ui.card} text-center text-slate-600">
				Todavía no hay clientes. Cree el primero para darles logo y colores a sus assessments.
			</p>
		{:else}
			<ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
				{#each clients as c (c.id)}
					<li>
						<Link
							href="/admin/clientes/{c.id}"
							class="flex h-full flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-5 hover:border-brand-300 hover:shadow-sm"
						>
							<div
								class="flex h-16 items-center justify-center rounded-xl p-2"
								style="background: {c.themePrimary ?? DEFAULT_PRIMARY}"
							>
								{#if c.logoUrl}
									<span class="flex h-full items-center rounded-lg bg-white px-2 py-1">
										<img src={c.logoUrl} alt="" class="h-full w-auto object-contain" />
									</span>
								{:else}
									<span class="text-xs text-white/80">Sin logo</span>
								{/if}
							</div>
							<div class="flex items-center justify-between gap-3">
								<p class="font-semibold">{c.name}</p>
								<span
									class="h-4 w-4 shrink-0 rounded-full ring-1 ring-slate-300"
									style="background: {c.themeAccent ?? DEFAULT_ACCENT}"
									aria-hidden="true"
								></span>
							</div>
							<p class="text-sm text-slate-600">
								{c.assessmentCount}
								{c.assessmentCount === 1 ? 'assessment' : 'assessments'}
							</p>
						</Link>
					</li>
				{/each}
			</ul>
		{/if}
	</div>
</AdminLayout>
