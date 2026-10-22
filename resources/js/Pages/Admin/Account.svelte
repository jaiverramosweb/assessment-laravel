<script lang="ts">
	import { useForm } from '@inertiajs/svelte';
	import AdminLayout from '@/Layouts/AdminLayout.svelte';
	import { ui } from '@/lib/ui';

	let { admin }: { admin: { id: string; email: string; name: string } } = $props();

	const form = useForm({ current_password: '', password: '', password_confirmation: '' });
	const errors = $derived($form.errors as Record<string, string | undefined>);

	function save(event: SubmitEvent) {
		event.preventDefault();
		$form.post('/admin/cuenta/password', {
			preserveScroll: true,
			preserveState: true,
			// Passwords never stay in the fields.
			onFinish: () => $form.reset()
		});
	}
</script>

<svelte:head>
	<title>Mi cuenta · Administración</title>
</svelte:head>

<AdminLayout>
	<div class="flex flex-col gap-8">
		<div class="flex flex-col gap-1.5">
			<p class={ui.eyebrow}>Panel de administración</p>
			<h1 class={ui.pageTitle}>Mi cuenta</h1>
			<p class="text-sm text-slate-600">{admin.name} · {admin.email}</p>
		</div>

		<form method="POST" onsubmit={save} class="{ui.card} grid max-w-xl gap-3">
			<h2 class="text-sm font-semibold">Cambiar contraseña</h2>
			<div>
				<label for="a-current" class={ui.label}>Contraseña actual</label>
				<input
					id="a-current"
					name="current_password"
					type="password"
					required
					autocomplete="current-password"
					bind:value={$form.current_password}
					aria-invalid={!!errors.current_password}
					class={ui.input}
				/>
				{#if errors.current_password}<p class={ui.fieldError}>{errors.current_password}</p>{/if}
			</div>
			<div>
				<label for="a-password" class={ui.label}>Nueva contraseña (mínimo 10 caracteres)</label>
				<input
					id="a-password"
					name="password"
					type="password"
					required
					minlength="10"
					autocomplete="new-password"
					bind:value={$form.password}
					aria-invalid={!!errors.password}
					class={ui.input}
				/>
				{#if errors.password}<p class={ui.fieldError}>{errors.password}</p>{/if}
			</div>
			<div>
				<label for="a-password2" class={ui.label}>Repita la nueva contraseña</label>
				<input
					id="a-password2"
					name="password_confirmation"
					type="password"
					required
					autocomplete="new-password"
					bind:value={$form.password_confirmation}
					class={ui.input}
				/>
			</div>
			<p class="text-xs text-slate-500">Se cerrarán sus sesiones abiertas en otros equipos.</p>
			<div>
				<button class={ui.btnPrimary} disabled={$form.processing}>Cambiar contraseña</button>
			</div>
		</form>
	</div>
</AdminLayout>
