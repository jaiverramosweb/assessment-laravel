<script lang="ts" module>
	import type { AssessmentStatus } from '@/lib/constants';

	/** Shared props of every assessment tab (app/Services/Shared/AssessmentContext.php). */
	export type AssessmentContext = {
		assessment: {
			id: string;
			title: string;
			status: AssessmentStatus;
			slug: string;
			timezone: string;
			accessMode: 'invitados' | 'abierto';
			openMaxChars: number;
			startsAt: number;
			endsAt: number;
			hasPassword: boolean;
			passwordViewable: boolean;
			client: { id: string; name: string; themePrimary: string | null; themeAccent: string | null } | null;
			logoUrl: string | null;
		};
		lockReason: string | null;
		locked: boolean;
		series: { id: string; title: string; order: number | null; status: AssessmentStatus }[] | null;
		questionCounts: { choice: number; open: number; total: number };
		participantCount: number;
		publicLink: string;
	};
</script>

<script lang="ts">
	import type { Snippet } from 'svelte';
	import { Link, page } from '@inertiajs/svelte';
	import AdminLayout from '@/Layouts/AdminLayout.svelte';
	import StatusBadge from '@/components/StatusBadge.svelte';

	let { context, children }: { context: AssessmentContext; children: Snippet } = $props();

	const base = $derived(`/admin/assessments/${context.assessment.id}`);
	const pathname = $derived(new URL($page.url, 'http://x').pathname);

	const tabs = $derived([
		{ href: base, label: 'General' },
		{ href: `${base}/preguntas`, label: `Preguntas (${context.questionCounts.total})` },
		{ href: `${base}/participantes`, label: `Participantes (${context.participantCount})` },
		{ href: `${base}/invitaciones`, label: 'Invitaciones' },
		{ href: `${base}/reportes`, label: 'Reportes' },
		...(context.series ? [{ href: `${base}/mediciones`, label: `Mediciones (${context.series.length})` }] : [])
	]);
</script>

<AdminLayout>
	<nav aria-label="Ruta" class="text-sm text-slate-600">
		<Link href="/admin" class="hover:text-ink-950 hover:underline">Assessments</Link> /
		<span class="text-ink-950">{context.assessment.title}</span>
	</nav>

	<div class="mt-3 flex flex-wrap items-center gap-4">
		{#if context.assessment.logoUrl}
			<img
				src={context.assessment.logoUrl}
				alt="Logo del cliente"
				class="h-12 w-12 rounded-xl border border-slate-200 bg-white object-contain p-1.5"
			/>
		{/if}
		<h1 class="font-display text-3xl font-extrabold tracking-tight">{context.assessment.title}</h1>
		<StatusBadge status={context.assessment.status} />
	</div>

	<nav aria-label="Secciones del assessment" class="mt-5 border-b border-slate-200">
		<ul class="-mb-px flex gap-2 overflow-x-auto text-sm">
			{#each tabs as tab (tab.href)}
				<li>
					<Link
						href={tab.href}
						aria-current={pathname === tab.href ? 'page' : undefined}
						class="inline-block border-b-2 border-transparent px-3 py-3 font-medium whitespace-nowrap text-slate-600 hover:text-ink-950 aria-[current=page]:border-brand-600 aria-[current=page]:font-semibold aria-[current=page]:text-ink-950"
					>
						{tab.label}
					</Link>
				</li>
			{/each}
		</ul>
	</nav>

	<div class="mt-6">
		{@render children()}
	</div>
</AdminLayout>
