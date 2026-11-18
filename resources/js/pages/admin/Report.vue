<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { exactUsd, formatUsd, toNanos } from '@/lib/money';

defineOptions({ layout: { breadcrumbs: [{ title: 'Financial report', href: '/admin/report' }] } });

type Money = { revenue: string; provider: string; profit: string };
type DailyRow = Money & { day: string; generations: number };
type UserRow = Money & { user_id: number; name: string; email: string; balance: string; generations: number };
type ReconRow = {
    day: string;
    pixelforge_nanos: string | null;
    pixelforge_generations: number | null;
    local_nanos: string;
    local_generations: number;
    diff_nanos: string | null;
    match: boolean;
};
type Reconciliation = { ok: boolean; error: string | null; rows: ReconRow[]; raw: unknown; fetched_at?: string };

const props = defineProps<{
    daily: DailyRow[];
    perUser: UserRow[];
    totals: Money & { generations: number; failed: number };
    reconciliation?: Reconciliation;
}>();

const loading = ref(false);
function reconcile() {
    loading.value = true;
    router.reload({ only: ['reconciliation'], onFinish: () => (loading.value = false) });
}

const allMatch = computed(() => props.reconciliation?.ok && props.reconciliation.rows.every((r) => r.match));
const usd = (v: string | null) => (v === null ? '—' : formatUsd(toNanos(v), { decimals: 6 }));
const exact = (v: string | null) => (v === null ? '' : `$${exactUsd(toNanos(v))}`);
</script>

<template>
    <Head title="Financial report" />

    <div class="space-y-6 p-4">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border p-4">
                <p class="text-xs text-muted-foreground">User spend (revenue)</p>
                <p class="font-mono text-xl font-semibold" :title="exact(totals.revenue)">{{ usd(totals.revenue) }}</p>
            </div>
            <div class="rounded-xl border p-4">
                <p class="text-xs text-muted-foreground">Provider cost</p>
                <p class="font-mono text-xl font-semibold" :title="exact(totals.provider)">{{ usd(totals.provider) }}</p>
            </div>
            <div class="rounded-xl border p-4">
                <p class="text-xs text-muted-foreground">Profit</p>
                <p class="font-mono text-xl font-semibold text-emerald-600 dark:text-emerald-400" :title="exact(totals.profit)">{{ usd(totals.profit) }}</p>
            </div>
            <div class="rounded-xl border p-4">
                <p class="text-xs text-muted-foreground">Generations</p>
                <p class="text-xl font-semibold">{{ totals.generations }} <span class="text-sm font-normal text-muted-foreground">billed · {{ totals.failed }} failed ($0)</span></p>
            </div>
        </div>

        <section class="rounded-xl border">
            <header class="flex flex-wrap items-center justify-between gap-2 border-b p-4">
                <div>
                    <h2 class="font-semibold">Reconcile with Pixelforge <code class="text-sm">/v1/usage</code></h2>
                    <p class="text-xs text-muted-foreground">Compares Pixelforge's billed cost per UTC day with our recorded provider cost.</p>
                </div>
                <Button size="sm" :disabled="loading" @click="reconcile">{{ loading ? 'Fetching…' : reconciliation ? 'Refresh' : 'Run reconciliation' }}</Button>
            </header>
            <div v-if="reconciliation" class="overflow-x-auto p-4">
                <p v-if="!reconciliation.ok" class="text-sm text-rose-600">Could not fetch usage: {{ reconciliation.error }}</p>
                <template v-else>
                    <p :class="['mb-3 text-sm font-medium', allMatch ? 'text-emerald-600' : 'text-amber-600']">
                        {{ allMatch ? '✓ Every day matches to the nanodollar.' : 'Some days differ. See the highlighted rows.' }}
                    </p>
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs text-muted-foreground">
                            <tr>
                                <th class="py-1 pr-4">UTC day</th>
                                <th class="py-1 pr-4 text-right">Pixelforge cost</th>
                                <th class="py-1 pr-4 text-right">Our provider cost</th>
                                <th class="py-1 pr-4 text-right">Difference</th>
                                <th class="py-1 text-right">Gens (PF / ours)</th>
                            </tr>
                        </thead>
                        <tbody class="font-mono">
                            <tr v-for="r in reconciliation.rows" :key="r.day" :class="['border-t', r.match ? '' : 'bg-amber-50 dark:bg-amber-950/30']">
                                <td class="py-1.5 pr-4">{{ r.day }}</td>
                                <td class="py-1.5 pr-4 text-right" :title="exact(r.pixelforge_nanos)">{{ usd(r.pixelforge_nanos) }}</td>
                                <td class="py-1.5 pr-4 text-right" :title="exact(r.local_nanos)">{{ usd(r.local_nanos) }}</td>
                                <td class="py-1.5 pr-4 text-right">{{ r.diff_nanos === null ? 'missing at PF' : r.diff_nanos === '0' ? '0' : usd(r.diff_nanos) }}</td>
                                <td class="py-1.5 text-right">{{ r.pixelforge_generations ?? '—' }} / {{ r.local_generations }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <details class="mt-3 text-xs text-muted-foreground">
                        <summary class="cursor-pointer">Raw /v1/usage response</summary>
                        <pre class="mt-1 max-h-64 overflow-auto rounded bg-muted p-2">{{ JSON.stringify(reconciliation.raw, null, 2) }}</pre>
                    </details>
                </template>
            </div>
        </section>

        <div class="grid gap-6 xl:grid-cols-2">
            <section class="overflow-x-auto rounded-xl border p-4">
                <h2 class="mb-3 font-semibold">Daily totals <span class="text-xs font-normal text-muted-foreground">(by UTC completion date)</span></h2>
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-muted-foreground">
                        <tr><th class="pr-4">Day</th><th class="pr-4 text-right">Gens</th><th class="pr-4 text-right">User spend</th><th class="pr-4 text-right">Provider</th><th class="text-right">Profit</th></tr>
                    </thead>
                    <tbody class="font-mono">
                        <tr v-for="r in daily" :key="r.day" class="border-t">
                            <td class="py-1.5 pr-4">{{ r.day }}</td>
                            <td class="pr-4 text-right">{{ r.generations }}</td>
                            <td class="pr-4 text-right" :title="exact(r.revenue)">{{ usd(r.revenue) }}</td>
                            <td class="pr-4 text-right" :title="exact(r.provider)">{{ usd(r.provider) }}</td>
                            <td class="text-right" :title="exact(r.profit)">{{ usd(r.profit) }}</td>
                        </tr>
                        <tr v-if="!daily.length"><td colspan="5" class="py-4 text-center text-muted-foreground">No billed generations yet.</td></tr>
                    </tbody>
                </table>
            </section>

            <section class="overflow-x-auto rounded-xl border p-4">
                <h2 class="mb-3 font-semibold">Per user</h2>
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-muted-foreground">
                        <tr><th class="pr-4">User</th><th class="pr-4 text-right">Gens</th><th class="pr-4 text-right">User spend</th><th class="pr-4 text-right">Provider</th><th class="pr-4 text-right">Profit</th><th class="text-right">Balance</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in perUser" :key="r.user_id" class="border-t">
                            <td class="py-1.5 pr-4"><div>{{ r.name }}</div><div class="text-xs text-muted-foreground">{{ r.email }}</div></td>
                            <td class="pr-4 text-right font-mono">{{ r.generations }}</td>
                            <td class="pr-4 text-right font-mono" :title="exact(r.revenue)">{{ usd(r.revenue) }}</td>
                            <td class="pr-4 text-right font-mono" :title="exact(r.provider)">{{ usd(r.provider) }}</td>
                            <td class="pr-4 text-right font-mono" :title="exact(r.profit)">{{ usd(r.profit) }}</td>
                            <td class="text-right font-mono" :title="exact(r.balance)">{{ usd(r.balance) }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </div>
</template>
