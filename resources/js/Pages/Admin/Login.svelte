<script lang="ts">
	import { page, useForm } from '@inertiajs/svelte';

	const destino = new URL($page.url, 'http://x').search;
	const form = useForm({ email: '', password: '' });

	function submit(event: SubmitEvent) {
		event.preventDefault();
		$form.post(`/admin/login${destino}`, {
			// The typed email is kept, but the password is cleared after an error.
			onError: () => {
				$form.reset('password');
				document.getElementById('password')?.focus();
			}
		});
	}
</script>

<svelte:head>
	<title>Ingreso de administradores</title>
</svelte:head>

<main class="flex min-h-screen items-center justify-center px-4 py-12">
	<div class="w-full max-w-sm">
		<span
			class="mx-auto mb-5 flex h-11 w-11 items-center justify-center rounded-xl bg-brand-600 text-white"
			aria-hidden="true"
		>
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
		<h1 class="text-center font-display text-2xl font-extrabold tracking-tight">Ingreso de administradores</h1>

		<form
			method="POST"
			onsubmit={submit}
			class="mt-8 space-y-5 rounded-2xl border border-slate-200 bg-white p-7 shadow-lg shadow-ink-950/5"
		>
			{#if $form.errors.form}
				<p role="alert" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">
					{$form.errors.form}
				</p>
			{/if}

			<div>
				<label for="email" class="block text-sm font-medium text-slate-700">Email</label>
				<input
					id="email"
					name="email"
					type="email"
					autocomplete="username"
					required
					bind:value={$form.email}
					class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-brand-500 focus:ring-brand-500"
				/>
			</div>

			<div>
				<label for="password" class="block text-sm font-medium text-slate-700">Contraseña</label>
				<input
					id="password"
					name="password"
					type="password"
					autocomplete="current-password"
					required
					bind:value={$form.password}
					class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-brand-500 focus:ring-brand-500"
				/>
			</div>

			<button
				type="submit"
				disabled={$form.processing}
				class="w-full rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:opacity-60"
			>
				{$form.processing ? 'Ingresando…' : 'Ingresar'}
			</button>
		</form>
	</div>
</main>
