<script lang="ts">
	// Diferencia en puntos porcentuales. El signo va en el texto: el color no es la única señal.
	import { formatPoints } from '@/lib/format';

	let { delta }: { delta: number | null } = $props();
	const pp = $derived(delta === null ? null : Math.round(delta * 100));
</script>

<span
	class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap tabular-nums ring-1 ring-inset {pp ===
	null
		? 'text-slate-500 ring-slate-200'
		: pp > 0
			? 'bg-emerald-50 text-emerald-800 ring-emerald-200'
			: pp < 0
				? 'bg-red-50 text-red-800 ring-red-200'
				: 'bg-slate-50 text-slate-700 ring-slate-200'}"
>
	{formatPoints(delta)}
</span>
