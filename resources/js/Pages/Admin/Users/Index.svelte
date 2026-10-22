<script lang="ts">
	import { Link, router, useForm } from '@inertiajs/svelte';
	import AdminLayout from '@/Layouts/AdminLayout.svelte';
	import { formatDateTime } from '@/lib/format';
	import { ui } from '@/lib/ui';

	type UserRow = {
		id: string;
		name: string;
		email: string;
		lastLoginAt: number | null;
		createdAt: number;
		active: boolean;
		isSelf: boolean;
	};

	let { users }: { users: UserRow[] } = $props();

	const createForm = useForm({ name: '', email: '', password: '', password_confirmation: '' });
	const createErrors = $derived($createForm.errors as Record<string, string | undefined>);

	function create(event: SubmitEvent) {
		event.preventDefault();
		$createForm.post('/admin/usuarios', {
			preserveScroll: true,
			preserveState: true,
			onSuccess: () => $createForm.reset(),
			onError: () => $createForm.reset('password', 'password_confirmation')
		});
	}

	// New password for another user (one row at a time).
	let resetId: string | null = $state(null);
	const resetForm = useForm({ password: '', password_confirmation: '' });
	const resetErrors = $derived($resetForm.errors as Record<string, string | undefined>);

	function startReset(u: UserRow) {
		$resetForm.reset();
		$resetForm.clearErrors();
		resetId = u.id;
	}

	function saveReset(event: SubmitEvent) {
		event.preventDefault();
		$resetForm.post(`/admin/usuarios/${resetId}/password`, {
			preserveScroll: true,
			preserveState: true,
			onSuccess: () => {
				resetId = null;
				$resetForm.reset();
			},
			onError: () => $resetForm.reset('password', 'password_confirmation')
		});
	}

	function setActive(u: UserRow, active: boolean) {
		if (!active && !confirm(`¿Desactivar a ${u.name}? No podrá ingresar hasta que lo active de nuevo.`)) return;
		router.post(`/admin/usuarios/${u.id}/${active ? 'activar' : 'desactivar'}`, {}, { preserveScroll: true });
	}
</script>

<svelte:head>
	<title>Usuarios · Administración</title>
</svelte:head>

<AdminLayout>
	<div class="flex flex-col gap-8">
		<div class="flex flex-col gap-1.5">
			<p class={ui.eyebrow}>Panel de administración</p>
			<h1 class={ui.pageTitle}>Usuarios</h1>
			<p class="max-w-2xl text-sm text-slate-600">
				Personas que pueden ingresar al panel. Todas tienen acceso completo. Para cambiar su propia
				contraseña use <Link href="/admin/cuenta" class="text-brand-600 underline">Mi cuenta</Link>.
			</p>
		</div>

		<details class={ui.card} open={Object.keys(createErrors).length > 0}>
			<summary class="cursor-pointer text-sm font-semibold">Crear usuario</summary>
			<form method="POST" onsubmit={create} class="mt-4 grid gap-3 sm:grid-cols-2">
				<div>
					<label for="u-name" class={ui.label}>Nombre</label>
					<input
						id="u-name"
						name="name"
						required
						maxlength="100"
						bind:value={$createForm.name}
						aria-invalid={!!createErrors.name}
						class={ui.input}
					/>
					{#if createErrors.name}<p class={ui.fieldError}>{createErrors.name}</p>{/if}
				</div>
				<div>
					<label for="u-email" class={ui.label}>Email</label>
					<input
						id="u-email"
						name="email"
						type="email"
						required
						bind:value={$createForm.email}
						aria-invalid={!!createErrors.email}
						class={ui.input}
					/>
					{#if createErrors.email}<p class={ui.fieldError}>{createErrors.email}</p>{/if}
				</div>
				<div>
					<label for="u-password" class={ui.label}>Contraseña inicial (mínimo 10 caracteres)</label>
					<input
						id="u-password"
						name="password"
						type="password"
						required
						minlength="10"
						autocomplete="new-password"
						bind:value={$createForm.password}
						aria-invalid={!!createErrors.password}
						class={ui.input}
					/>
					{#if createErrors.password}<p class={ui.fieldError}>{createErrors.password}</p>{/if}
				</div>
				<div>
					<label for="u-password2" class={ui.label}>Repita la contraseña</label>
					<input
						id="u-password2"
						name="password_confirmation"
						type="password"
						required
						autocomplete="new-password"
						bind:value={$createForm.password_confirmation}
						class={ui.input}
					/>
				</div>
				<div class="sm:col-span-2">
					<button class={ui.btnPrimary} disabled={$createForm.processing}>Crear usuario</button>
				</div>
			</form>
		</details>

		<div class="overflow-x-auto rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
			<table class="min-w-full divide-y divide-slate-200 text-sm">
				<caption class="sr-only">Usuarios del panel</caption>
				<thead class="bg-slate-50 text-left text-slate-600">
					<tr>
						<th scope="col" class="px-4 py-2 font-medium">Nombre</th>
						<th scope="col" class="px-4 py-2 font-medium">Email</th>
						<th scope="col" class="px-4 py-2 font-medium">Último ingreso</th>
						<th scope="col" class="px-4 py-2 font-medium">Estado</th>
						<th scope="col" class="px-4 py-2"><span class="sr-only">Acciones</span></th>
					</tr>
				</thead>
				<tbody class="divide-y divide-slate-100">
					{#each users as u (u.id)}
						<tr class="align-top">
							<td class="px-4 py-2">
								{u.name}
								{#if u.isSelf}<span class="ml-1 text-xs text-slate-500">(usted)</span>{/if}
							</td>
							<td class="px-4 py-2 text-slate-600">{u.email}</td>
							<td class="px-4 py-2 whitespace-nowrap text-slate-600">
								{#if u.lastLoginAt}{formatDateTime(u.lastLoginAt)}{:else}<span class="text-slate-400">Nunca</span>{/if}
							</td>
							<td class="px-4 py-2">
								{#if u.active}
									<span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-emerald-200"
										>Activo</span
									>
								{:else}
									<span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 ring-1 ring-slate-300"
										>Inactivo</span
									>
								{/if}
							</td>
							<td class="px-4 py-2 text-right whitespace-nowrap">
								{#if !u.isSelf}
									<button
										type="button"
										onclick={() => startReset(u)}
										class="text-sm text-brand-600 hover:underline"
										aria-label="Cambiar contraseña de {u.name}">Cambiar contraseña</button
									>
									{#if u.active}
										<button
											type="button"
											onclick={() => setActive(u, false)}
											class="ml-3 text-sm text-red-700 hover:underline"
											aria-label="Desactivar a {u.name}">Desactivar</button
										>
									{:else}
										<button
											type="button"
											onclick={() => setActive(u, true)}
											class="ml-3 text-sm text-brand-600 hover:underline"
											aria-label="Activar a {u.name}">Activar</button
										>
									{/if}
								{/if}
							</td>
						</tr>
						{#if resetId === u.id}
							<tr class="bg-brand-50/40">
								<td colspan="5" class="px-4 py-3">
									<form method="POST" onsubmit={saveReset} class="flex flex-wrap items-end gap-3">
										<div>
											<label for="r-password" class={ui.label}>Nueva contraseña para {u.email}</label>
											<input
												id="r-password"
												type="password"
												required
												minlength="10"
												autocomplete="new-password"
												bind:value={$resetForm.password}
												aria-invalid={!!resetErrors.password}
												class={ui.input}
											/>
										</div>
										<div>
											<label for="r-password2" class={ui.label}>Repita la contraseña</label>
											<input
												id="r-password2"
												type="password"
												required
												autocomplete="new-password"
												bind:value={$resetForm.password_confirmation}
												class={ui.input}
											/>
										</div>
										<button class={ui.btnPrimary} disabled={$resetForm.processing}>Guardar</button>
										<button type="button" onclick={() => (resetId = null)} class={ui.btnSecondary}>Cancelar</button>
									</form>
									{#if resetErrors.password}<p class={ui.fieldError}>{resetErrors.password}</p>{/if}
									<p class="mt-2 text-xs text-slate-500">Sus sesiones abiertas se cerrarán.</p>
								</td>
							</tr>
						{/if}
					{/each}
				</tbody>
			</table>
		</div>
	</div>
</AdminLayout>
