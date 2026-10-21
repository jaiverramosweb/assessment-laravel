<script lang="ts">
	import type { Snippet } from 'svelte';
	import { Link, page, router } from '@inertiajs/svelte';
	import type { SharedProps } from '@/types';
	import { ui } from '@/lib/ui';

	let { children }: { children: Snippet } = $props();

	const props = $derived($page.props as unknown as SharedProps);
	const pathname = $derived(new URL($page.url, 'http://x').pathname);

	const links = [
		{ href: '/admin', label: 'Assessments', icon: 'grid', exclude: ['/admin/auditoria', '/admin/clientes'] },
		{ href: '/admin/clientes', label: 'Clientes', icon: 'users', exclude: [] },
		{ href: '/admin/auditoria', label: 'Auditoría', icon: 'clock', exclude: [] }
	];

	function isActive(href: string, exclude: string[]) {
		if (exclude.some((e) => pathname.startsWith(e))) return false;
		return pathname === href || pathname.startsWith(href + '/');
	}

	const name = $derived(props.admin?.name ?? '');
	const initials = $derived(
		name
			.split(/\s+/)
			.slice(0, 2)
			.map((w) => w[0]?.toUpperCase() ?? '')
			.join('')
	);

	function logout(event: SubmitEvent) {
		event.preventDefault();
		router.post('/admin/logout');
	}
</script>

<div class="flex min-h-screen flex-wrap bg-canvas lg:flex-nowrap">
	<!-- Sidebar (on narrow screens it sits on top) -->
	<header
		class="flex w-full flex-col gap-6 bg-ink-950 px-5 py-6 text-ink-300 lg:sticky lg:top-0 lg:h-screen lg:w-64 lg:shrink-0"
	>
		<Link href="/admin" class="flex items-center gap-2.5 text-white">
			<span class="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-600" aria-hidden="true">
				<svg
					width="18"
					height="18"
					viewBox="0 0 24 24"
					fill="none"
					stroke="currentColor"
					stroke-width="2.2"
					stroke-linecap="round"
					stroke-linejoin="round"
					><path d="M9 11l3 3 8-8"></path><path d="M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h9"
					></path></svg
				>
			</span>
			<span class="font-display text-lg font-extrabold">Assessments</span>
		</Link>

		<nav aria-label="Principal" class="flex flex-row flex-wrap gap-1 lg:flex-col">
			{#each links as link (link.href)}
				<Link
					href={link.href}
					aria-current={isActive(link.href, link.exclude) ? 'page' : undefined}
					class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-ink-300 hover:bg-ink-900 hover:text-white aria-[current=page]:bg-ink-900 aria-[current=page]:font-semibold aria-[current=page]:text-white"
				>
					{#if link.icon === 'grid'}
						<svg
							width="18"
							height="18"
							viewBox="0 0 24 24"
							fill="none"
							stroke="currentColor"
							stroke-width="2"
							stroke-linecap="round"
							stroke-linejoin="round"
							aria-hidden="true"
							><rect x="3" y="3" width="7" height="7" rx="1.5"></rect><rect x="14" y="3" width="7" height="7" rx="1.5"
							></rect><rect x="3" y="14" width="7" height="7" rx="1.5"></rect><rect
								x="14"
								y="14"
								width="7"
								height="7"
								rx="1.5"
							></rect></svg
						>
					{:else if link.icon === 'users'}
						<svg
							width="18"
							height="18"
							viewBox="0 0 24 24"
							fill="none"
							stroke="currentColor"
							stroke-width="2"
							stroke-linecap="round"
							stroke-linejoin="round"
							aria-hidden="true"
							><path d="M3 21V7l9-4 9 4v14"></path><path d="M9 21v-6h6v6"></path><path
								d="M8 10h.01M12 10h.01M16 10h.01"
							></path></svg
						>
					{:else}
						<svg
							width="18"
							height="18"
							viewBox="0 0 24 24"
							fill="none"
							stroke="currentColor"
							stroke-width="2"
							stroke-linecap="round"
							stroke-linejoin="round"
							aria-hidden="true"><path d="M12 8v4l3 2"></path><circle cx="12" cy="12" r="9"></circle></svg
						>
					{/if}
					{link.label}
				</Link>
			{/each}
		</nav>

		<div class="flex items-center gap-3 border-t border-ink-800 pt-5 lg:mt-auto">
			<span
				class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-ink-900 text-xs font-semibold text-white"
				aria-hidden="true">{initials}</span
			>
			<div class="flex min-w-0 flex-col">
				<span class="truncate text-sm font-semibold text-white">{name}</span>
				<form method="POST" action="/admin/logout" onsubmit={logout}>
					<button type="submit" class="text-sm text-ink-400 hover:text-white hover:underline">
						Cerrar sesión
					</button>
				</form>
			</div>
		</div>
	</header>

	<main class="min-w-0 flex-1 px-4 py-8 sm:px-8 lg:px-10">
		<div class="mx-auto max-w-6xl">
			{#if props.flash?.success}
				<p role="status" class="mb-6 {ui.alertSuccess}">{props.flash.success}</p>
			{/if}
			{#if props.flash?.error}
				<p role="alert" class="mb-6 {ui.alertError}">{props.flash.error}</p>
			{/if}
			{@render children()}
		</div>
	</main>
</div>
