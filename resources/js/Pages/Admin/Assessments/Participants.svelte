<script lang="ts">
	import { Link, router, useForm } from '@inertiajs/svelte';
	import AssessmentLayout, { type AssessmentContext } from '@/Layouts/AssessmentLayout.svelte';
	import ParticipantStatusBadge from '@/components/ParticipantStatusBadge.svelte';
	import { PARTICIPANT_STATUS_LABELS, type ParticipantStatus } from '@/lib/constants';
	import { formatDateTime } from '@/lib/format';
	import { ui } from '@/lib/ui';

	type ParticipantRow = {
		id: string;
		name: string;
		email: string;
		area: string | null;
		cargo: string | null;
		source: 'cargado' | 'abierto';
		invitedAt: number | null;
		startedAt: number | null;
		submittedAt: number | null;
		status: ParticipantStatus;
		lastEmailStatus: 'enviado' | 'error' | null;
		lastEmailError: string | null;
	};

	type Outcome = {
		added?: string;
		imported?: { created: number; updated: number };
		importErrors?: string[];
		deleted?: boolean;
		deleteError?: string;
		edited?: string;
		editError?: string;
	} | null;

	type Props = {
		context: AssessmentContext;
		participants: ParticipantRow[];
		counts: { total: number; pendiente: number; en_progreso: number; completado: number; sinInvitar: number };
		filters: { estado: string; area: string; cargo: string; q: string };
		statuses: ParticipantStatus[];
		areas: string[];
		cargos: string[];
		outcome: Outcome;
	};

	let { context, participants, counts, filters, statuses, areas, cargos, outcome }: Props = $props();

	const a = $derived(context.assessment);
	const base = $derived(`/admin/assessments/${context.assessment.id}/participantes`);
	const hasFilters = $derived(Object.values(filters).some(Boolean));

	const addForm = useForm({ name: '', email: '', area: '', cargo: '' });
	const importForm = useForm<{ archivo: File | null }>({ archivo: null });
	let fileInput: HTMLInputElement | undefined = $state();

	const addErrors = $derived($addForm.errors as Record<string, string | undefined>);
	const hasAddErrors = $derived(Object.keys(addErrors).length > 0);

	function add(event: SubmitEvent) {
		event.preventDefault();
		$addForm.post(base, {
			preserveScroll: true,
			preserveState: true,
			onSuccess: () => $addForm.reset()
		});
	}

	function importFile(event: SubmitEvent) {
		event.preventDefault();
		$importForm.post(`${base}/importar`, {
			forceFormData: true,
			preserveScroll: true,
			preserveState: true,
			onSuccess: () => {
				$importForm.reset();
				if (fileInput) fileInput.value = '';
			}
		});
	}

	function filter(event: SubmitEvent) {
		event.preventDefault();
		const data = new FormData(event.currentTarget as HTMLFormElement);
		const query: Record<string, string> = {};
		for (const [key, value] of data) if (value) query[key] = String(value);
		router.get(base, query, { preserveState: true, preserveScroll: true });
	}

	// Inline edit of one row (name, area, cargo). The email is not editable.
	let editingId: string | null = $state(null);
	const editForm = useForm({ name: '', area: '', cargo: '' });
	const editErrors = $derived($editForm.errors as Record<string, string | undefined>);

	function startEdit(p: ParticipantRow) {
		$editForm.clearErrors();
		$editForm.name = p.name;
		$editForm.area = p.area ?? '';
		$editForm.cargo = p.cargo ?? '';
		editingId = p.id;
	}

	function saveEdit(event: SubmitEvent) {
		event.preventDefault();
		$editForm.post(`${base}/${editingId}`, {
			preserveScroll: true,
			preserveState: true,
			onSuccess: () => (editingId = null)
		});
	}

	function remove(p: ParticipantRow) {
		if (!confirm(`¿Eliminar a ${p.name}?`)) return;
		router.delete(`${base}/${p.id}`, { preserveScroll: true, preserveState: true });
	}
</script>

<svelte:head>
	<title>Participantes · {a.title}</title>
</svelte:head>

<AssessmentLayout {context}>
	<div class="space-y-6">
		{#if a.accessMode === 'abierto'}
			<p class={ui.alertWarning}>
				Este assessment está en modo <strong>abierto</strong>: cualquier persona con la contraseña
				puede responder y aparecerá aquí automáticamente. La lista sirve para enviar invitaciones.
			</p>
		{/if}

		<!-- Resumen -->
		<dl class="grid grid-cols-2 gap-3 sm:grid-cols-5">
			{#each [['Total', counts.total], [PARTICIPANT_STATUS_LABELS.pendiente, counts.pendiente], [PARTICIPANT_STATUS_LABELS.en_progreso, counts.en_progreso], [PARTICIPANT_STATUS_LABELS.completado, counts.completado], ['Sin invitar', counts.sinInvitar]] as [label, value] (label)}
				<div class="rounded-lg bg-white px-4 py-3 shadow-sm ring-1 ring-slate-200">
					<dt class="text-xs text-slate-500">{label}</dt>
					<dd class="text-xl font-semibold tabular-nums">{value}</dd>
				</div>
			{/each}
		</dl>

		<!-- Agregar e importar -->
		<div class="grid gap-4 lg:grid-cols-2">
			<details class={ui.card} open={hasAddErrors}>
				<summary class="cursor-pointer text-sm font-semibold">Agregar participante</summary>
				<form method="POST" onsubmit={add} class="mt-4 grid gap-3 sm:grid-cols-2">
					<div>
						<label for="p-name" class={ui.label}>Nombre</label>
						<input
							id="p-name"
							name="name"
							required
							bind:value={$addForm.name}
							aria-invalid={!!addErrors.name}
							class={ui.input}
						/>
						{#if addErrors.name}<p class={ui.fieldError}>{addErrors.name}</p>{/if}
					</div>
					<div>
						<label for="p-email" class={ui.label}>Email</label>
						<input
							id="p-email"
							name="email"
							type="email"
							required
							bind:value={$addForm.email}
							aria-invalid={!!addErrors.email}
							class={ui.input}
						/>
						{#if addErrors.email}<p class={ui.fieldError}>{addErrors.email}</p>{/if}
					</div>
					<div>
						<label for="p-area" class={ui.label}
							>Área <span class="text-slate-400">(opcional)</span></label
						>
						<input
							id="p-area"
							name="area"
							bind:value={$addForm.area}
							aria-invalid={!!addErrors.area}
							class={ui.input}
						/>
						{#if addErrors.area}<p class={ui.fieldError}>{addErrors.area}</p>{/if}
					</div>
					<div>
						<label for="p-cargo" class={ui.label}
							>Cargo <span class="text-slate-400">(opcional)</span></label
						>
						<input
							id="p-cargo"
							name="cargo"
							bind:value={$addForm.cargo}
							aria-invalid={!!addErrors.cargo}
							class={ui.input}
						/>
						{#if addErrors.cargo}<p class={ui.fieldError}>{addErrors.cargo}</p>{/if}
					</div>
					<div class="sm:col-span-2">
						<button class={ui.btnPrimary} disabled={$addForm.processing}>Agregar</button>
					</div>
				</form>
			</details>

			<details class={ui.card} open={!!outcome?.importErrors || !!outcome?.imported}>
				<summary class="cursor-pointer text-sm font-semibold">Cargar desde Excel o CSV</summary>
				<div class="mt-4 space-y-3 text-sm">
					<p class="text-slate-600">
						Columnas: <code>nombre</code>, <code>email</code> y opcionalmente <code>area</code>,
						<code>cargo</code> u otras. Descargue la
						<a href="{base}/plantilla" class="text-brand-600 underline" download>plantilla</a>. Si un
						email ya existe, se actualizan sus datos.
					</p>
					<form
						method="POST"
						enctype="multipart/form-data"
						onsubmit={importFile}
						class="flex flex-wrap items-end gap-3"
					>
						<div>
							<label for="archivo" class={ui.label}>Archivo (.xlsx o .csv, máx. 2 MB)</label>
							<input
								bind:this={fileInput}
								id="archivo"
								name="archivo"
								type="file"
								accept=".xlsx,.csv"
								required
								onchange={(e) => ($importForm.archivo = e.currentTarget.files?.[0] ?? null)}
								class="mt-1 block text-sm file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium hover:file:bg-slate-200"
							/>
						</div>
						<button class={ui.btnSecondary} disabled={$importForm.processing}>Cargar</button>
					</form>
					{#if outcome?.importErrors}
						<div class={ui.alertError} role="alert">
							<p class="font-medium">No se cargó ningún participante:</p>
							<ul class="mt-1 max-h-60 list-disc overflow-y-auto pl-5">
								{#each outcome.importErrors as e (e)}<li>{e}</li>{/each}
							</ul>
						</div>
					{/if}
					{#if outcome?.imported}
						<p class={ui.alertSuccess} role="status">
							Carga completa: {outcome.imported.created} nuevos, {outcome.imported.updated} actualizados.
						</p>
					{/if}
				</div>
			</details>
		</div>

		{#if outcome?.added}
			<p class={ui.alertSuccess} role="status">Participante agregado: {outcome.added}.</p>
		{/if}
		{#if outcome?.edited}
			<p class={ui.alertSuccess} role="status">Participante actualizado: {outcome.edited}.</p>
		{/if}
		{#if outcome?.editError}
			<p class={ui.alertError} role="alert">{outcome.editError}</p>
		{/if}
		{#if outcome?.deleteError}
			<p class={ui.alertError} role="alert">{outcome.deleteError}</p>
		{/if}

		<!-- Filtros -->
		<form method="GET" onsubmit={filter} class="flex flex-wrap items-end gap-3">
			<div>
				<label for="f-q" class={ui.label}>Buscar</label>
				<input
					id="f-q"
					name="q"
					type="search"
					value={filters.q}
					placeholder="Nombre o email"
					class={ui.input}
				/>
			</div>
			<div>
				<label for="f-estado" class={ui.label}>Estado</label>
				<select id="f-estado" name="estado" value={filters.estado} class={ui.input}>
					<option value="">Todos</option>
					{#each statuses as s (s)}<option value={s}>{PARTICIPANT_STATUS_LABELS[s]}</option>{/each}
				</select>
			</div>
			{#if areas.length}
				<div>
					<label for="f-area" class={ui.label}>Área</label>
					<select id="f-area" name="area" value={filters.area} class={ui.input}>
						<option value="">Todas</option>
						{#each areas as v (v)}<option value={v}>{v}</option>{/each}
					</select>
				</div>
			{/if}
			{#if cargos.length}
				<div>
					<label for="f-cargo" class={ui.label}>Cargo</label>
					<select id="f-cargo" name="cargo" value={filters.cargo} class={ui.input}>
						<option value="">Todos</option>
						{#each cargos as v (v)}<option value={v}>{v}</option>{/each}
					</select>
				</div>
			{/if}
			<button class={ui.btnSecondary}>Filtrar</button>
			{#if hasFilters}
				<Link href={base} class="py-2 text-sm text-brand-600 hover:underline">Quitar filtros</Link>
			{/if}
		</form>

		<!-- Tabla -->
		{#if participants.length === 0}
			<p class="{ui.card} text-center text-slate-600">
				{hasFilters
					? 'Ningún participante coincide con los filtros.'
					: 'Todavía no hay participantes.'}
			</p>
		{:else}
			<div class="overflow-x-auto rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
				<table class="min-w-full divide-y divide-slate-200 text-sm">
					<caption class="sr-only">Participantes del assessment</caption>
					<thead class="bg-slate-50 text-left text-slate-600">
						<tr>
							<th scope="col" class="px-4 py-2 font-medium">Nombre</th>
							<th scope="col" class="px-4 py-2 font-medium">Email</th>
							<th scope="col" class="px-4 py-2 font-medium">Área</th>
							<th scope="col" class="px-4 py-2 font-medium">Cargo</th>
							<th scope="col" class="px-4 py-2 font-medium">Estado</th>
							<th scope="col" class="px-4 py-2 font-medium">Invitación</th>
							<th scope="col" class="px-4 py-2"><span class="sr-only">Acciones</span></th>
						</tr>
					</thead>
					<tbody class="divide-y divide-slate-100">
						{#each participants as p (p.id)}
							{#if editingId === p.id}
								<tr class="bg-brand-50/40 align-top">
									<td class="px-4 py-2">
										<label for="e-name" class="sr-only">Nombre</label>
										<input
											id="e-name"
											form="edit-participant"
											required
											maxlength="150"
											bind:value={$editForm.name}
											aria-invalid={!!editErrors.name}
											class={ui.input}
										/>
										{#if editErrors.name}<p class={ui.fieldError}>{editErrors.name}</p>{/if}
									</td>
									<td class="px-4 py-2 text-slate-600">{p.email}</td>
									<td class="px-4 py-2">
										<label for="e-area" class="sr-only">Área</label>
										<input
											id="e-area"
											form="edit-participant"
											maxlength="100"
											placeholder="Área"
											bind:value={$editForm.area}
											aria-invalid={!!editErrors.area}
											class={ui.input}
										/>
										{#if editErrors.area}<p class={ui.fieldError}>{editErrors.area}</p>{/if}
									</td>
									<td class="px-4 py-2">
										<label for="e-cargo" class="sr-only">Cargo</label>
										<input
											id="e-cargo"
											form="edit-participant"
											maxlength="100"
											placeholder="Cargo"
											bind:value={$editForm.cargo}
											aria-invalid={!!editErrors.cargo}
											class={ui.input}
										/>
										{#if editErrors.cargo}<p class={ui.fieldError}>{editErrors.cargo}</p>{/if}
									</td>
									<td class="px-4 py-2"><ParticipantStatusBadge status={p.status} /></td>
									<td class="px-4 py-2"></td>
									<td class="px-4 py-2 text-right whitespace-nowrap">
										<form id="edit-participant" method="POST" onsubmit={saveEdit} class="inline">
											<button class="text-sm font-medium text-brand-600 hover:underline" disabled={$editForm.processing}
												>Guardar</button
											>
										</form>
										<button
											type="button"
											onclick={() => (editingId = null)}
											class="ml-3 text-sm text-slate-600 hover:underline">Cancelar</button
										>
									</td>
								</tr>
							{:else}
							<tr>
								<td class="px-4 py-2">
									{p.name}
									{#if p.source === 'abierto'}
										<span class="ml-1 text-xs text-slate-500">(ingreso abierto)</span>
									{/if}
								</td>
								<td class="px-4 py-2 text-slate-600">{p.email}</td>
								<td class="px-4 py-2 text-slate-600">{p.area ?? '—'}</td>
								<td class="px-4 py-2 text-slate-600">{p.cargo ?? '—'}</td>
								<td class="px-4 py-2"><ParticipantStatusBadge status={p.status} /></td>
								<td class="px-4 py-2 whitespace-nowrap text-slate-600">
									{#if p.lastEmailStatus === 'error'}
										<span class="text-red-700" title={p.lastEmailError ?? ''}>Error al enviar</span>
									{:else if p.invitedAt}
										{formatDateTime(p.invitedAt, a.timezone)}
									{:else}
										<span class="text-slate-400">No enviada</span>
									{/if}
								</td>
								<td class="px-4 py-2 text-right whitespace-nowrap">
									<button
										type="button"
										onclick={() => startEdit(p)}
										class="text-sm text-brand-600 hover:underline"
										aria-label="Editar a {p.name}">Editar</button
									>
									{#if p.status === 'pendiente'}
										<button
											type="button"
											onclick={() => remove(p)}
											class="ml-3 text-sm text-red-700 hover:underline"
											aria-label="Eliminar a {p.name}">Eliminar</button
										>
									{/if}
								</td>
							</tr>
							{/if}
						{/each}
					</tbody>
				</table>
			</div>
		{/if}
	</div>
</AssessmentLayout>
