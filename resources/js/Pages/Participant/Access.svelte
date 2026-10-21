<script lang="ts">
	import { page, router } from '@inertiajs/svelte';
	import ClientLogo from '@/components/ClientLogo.svelte';
	import { formatLongDateTime } from '@/lib/format';
	import { ui } from '@/lib/ui';

	type Availability = 'no_disponible' | 'no_iniciado' | 'cerrado' | 'abierto';
	type Props = {
		assessment: {
			title: string;
			description: string;
			accessMode: 'invitados' | 'abierto';
			startsAt: number;
			endsAt: number;
			timezone: string;
			logoUrl: string | null;
			themeStyle: string | null;
		};
		availability: Availability;
		// Result of the last attempt (flashed by the server), or null.
		form: { values: { email: string; name: string }; error: string; needName: boolean } | null;
	};

	let { assessment: a, availability, form }: Props = $props();

	// svelte-ignore state_referenced_locally
	let email = $state(form?.values?.email ?? '');
	// svelte-ignore state_referenced_locally
	let name = $state(form?.values?.name ?? '');
	let password = $state('');
	let enviando = $state(false);
	const showName = $derived(a.accessMode === 'abierto');
	const action = $derived(new URL($page.url, 'http://x').pathname);

	function submit(event: SubmitEvent) {
		event.preventDefault();
		enviando = true;
		router.post(
			action,
			{ password, email, name },
			{
				preserveState: true,
				preserveScroll: true,
				onFinish: () => {
					// After a failed attempt the typed email and name are kept, the password is cleared.
					password = '';
					enviando = false;
				}
			}
		);
	}
</script>

<svelte:head>
	<title>{a.title}</title>
	<meta name="robots" content="noindex" />
</svelte:head>

<!-- style: colores del cliente (variables de Tailwind), calculados en el servidor. -->
<div class="min-h-screen bg-canvas" style={a.themeStyle}>
	<!-- Encabezado con el logo del cliente -->
	<header class="bg-ink-950 px-5 pt-10 pb-20 text-white">
		<div class="mx-auto flex max-w-lg flex-col gap-4">
			<ClientLogo src={a.logoUrl} />
			<h1 class="font-display text-3xl leading-tight font-extrabold">{a.title}</h1>
			{#if a.description}
				<p class="text-[15px] leading-relaxed whitespace-pre-line text-ink-300">{a.description}</p>
			{/if}
			<p class="flex items-start gap-2 text-sm text-white">
				<svg
					class="mt-0.5 shrink-0"
					width="16"
					height="16"
					viewBox="0 0 24 24"
					fill="none"
					stroke="currentColor"
					stroke-width="2"
					stroke-linecap="round"
					aria-hidden="true"
					><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M16 3v4M8 3v4M3 10h18"
					></path></svg
				>
				<span>
					Disponible del {formatLongDateTime(a.startsAt, a.timezone)} al {formatLongDateTime(
						a.endsAt,
						a.timezone
					)} (hora de {a.timezone.replaceAll('_', ' ')}).
				</span>
			</p>
		</div>
	</header>

	<main class="mx-auto -mt-12 max-w-lg px-4 pb-12">
		{#if availability !== 'abierto'}
			<div class="rounded-2xl bg-white p-6 shadow-lg shadow-ink-950/5" role="status">
				{#if availability === 'no_iniciado'}
					<p class="text-ink-950">
						<strong>Aún no está disponible.</strong> Podrá ingresar desde el
						{formatLongDateTime(a.startsAt, a.timezone)}.
					</p>
				{:else if availability === 'cerrado'}
					<p class="text-ink-950">
						<strong>Este assessment ya cerró.</strong> Ya no se reciben respuestas.
					</p>
				{:else}
					<p class="text-ink-950">Este assessment no está disponible.</p>
				{/if}
			</div>
		{:else}
			<form
				method="POST"
				class="flex flex-col gap-5 rounded-2xl bg-white p-6 shadow-lg shadow-ink-950/5"
				onsubmit={submit}
			>
				{#if form?.error}
					<p role="alert" class={ui.alertError}>{form.error}</p>
				{/if}

				<div>
					<label for="password" class={ui.label}>Contraseña de acceso</label>
					<input
						id="password"
						name="password"
						type="password"
						autocomplete="off"
						required
						bind:value={password}
						class="{ui.input} py-3 text-base"
					/>
					<p class={ui.hint}>La que recibió en la invitación.</p>
				</div>

				<div>
					<label for="email" class={ui.label}>Su correo electrónico</label>
					<input
						id="email"
						name="email"
						type="email"
						autocomplete="email"
						required
						bind:value={email}
						class="{ui.input} py-3 text-base"
					/>
				</div>

				{#if showName || form?.needName}
					<div>
						<label for="name" class={ui.label}>
							Nombre completo
							{#if !form?.needName}<span class="font-normal text-slate-600"
									>(si es su primer ingreso)</span
								>{/if}
						</label>
						<input
							id="name"
							name="name"
							autocomplete="name"
							required={form?.needName}
							bind:value={name}
							class="{ui.input} py-3 text-base"
						/>
					</div>
				{/if}

				<button type="submit" disabled={enviando} class="{ui.btnPrimary} w-full py-3.5 text-base">
					{enviando ? 'Ingresando…' : 'Ingresar'}
				</button>
			</form>

			<ul class="mt-6 flex flex-col gap-3 px-2 text-sm leading-relaxed text-slate-700">
				<li class="flex gap-3">
					<svg
						class="mt-0.5 shrink-0 text-brand-700"
						width="18"
						height="18"
						viewBox="0 0 24 24"
						fill="none"
						stroke="currentColor"
						stroke-width="2"
						stroke-linecap="round"
						stroke-linejoin="round"
						aria-hidden="true"
						><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><path
							d="M17 21v-8H7v8M7 3v5h8"
						></path></svg
					>
					Sus respuestas se guardan automáticamente.
				</li>
				<li class="flex gap-3">
					<svg
						class="mt-0.5 shrink-0 text-brand-700"
						width="18"
						height="18"
						viewBox="0 0 24 24"
						fill="none"
						stroke="currentColor"
						stroke-width="2"
						stroke-linecap="round"
						stroke-linejoin="round"
						aria-hidden="true"
						><path d="M3 12a9 9 0 1 0 3-6.7L3 8"></path><path d="M3 3v5h5"></path></svg
					>
					Si sale, puede volver a ingresar con la misma contraseña y correo para continuar.
				</li>
				<li class="flex gap-3">
					<svg
						class="mt-0.5 shrink-0 text-brand-700"
						width="18"
						height="18"
						viewBox="0 0 24 24"
						fill="none"
						stroke="currentColor"
						stroke-width="2"
						stroke-linecap="round"
						stroke-linejoin="round"
						aria-hidden="true"
						><circle cx="12" cy="12" r="9"></circle><path d="M12 8v4M12 16h.01"></path></svg
					>
					Solo se permite una entrega por persona.
				</li>
			</ul>
		{/if}
	</main>
</div>
