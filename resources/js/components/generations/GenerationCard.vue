<script setup lang="ts">
import { computed } from 'vue';
import StatusBadge from '@/components/generations/StatusBadge.vue';
import { exactUsd, formatUsd, toNanos } from '@/lib/money';
import type { Generation } from '@/types/generation';

const props = defineProps<{ generation: Generation }>();
const g = computed(() => props.generation);

const estimated = computed(() => toNanos(g.value.estimated_user_cost_nanos));
const actual = computed(() => (g.value.actual_user_cost_nanos === null ? null : toNanos(g.value.actual_user_cost_nanos)));
const saved = computed(() => (actual.value === null ? null : estimated.value - actual.value));

const statusHint = computed(() => {
    switch (g.value.status) {
        case 'submitting':
            return g.value.retrying ? 'Pixelforge is busy. We will keep retrying automatically.' : 'Sending to Pixelforge…';
        case 'queued':
            return 'Waiting in Pixelforge’s queue.';
        case 'processing':
            return 'Generating your image…';
        default:
            return null;
    }
});

const created = computed(() => new Date(g.value.created_at).toLocaleString());
</script>

<template>
    <article class="flex flex-col overflow-hidden rounded-xl border border-sidebar-border/70 bg-card sm:flex-row dark:border-sidebar-border">
        <div class="flex aspect-square w-full shrink-0 items-center justify-center bg-muted sm:w-48">
            <a v-if="g.image_url" :href="g.image_url" target="_blank" rel="noopener" class="block size-full">
                <img :src="g.image_url" :alt="g.prompt" class="size-full object-cover" loading="lazy" />
            </a>
            <p v-else-if="g.image_expired" class="px-4 text-center text-xs text-muted-foreground">
                The image link expired. Pixelforge only keeps result URLs for a limited time.
            </p>
            <div v-else-if="g.status === 'failed'" class="px-4 text-center text-xs text-rose-600 dark:text-rose-400">No image</div>
            <div v-else class="size-10 animate-spin rounded-full border-2 border-muted-foreground/30 border-t-muted-foreground" />
        </div>

        <div class="flex min-w-0 flex-1 flex-col gap-3 p-4">
            <div class="flex flex-wrap items-center gap-2">
                <StatusBadge :status="g.status" :retrying="g.retrying" />
                <span class="text-xs text-muted-foreground">{{ g.size }} · {{ g.quality }}</span>
                <span v-if="g.reference_images.length" class="text-xs text-muted-foreground">· {{ g.reference_images.length }} ref</span>
                <span class="ml-auto text-xs text-muted-foreground">{{ created }}</span>
            </div>

            <p class="line-clamp-3 text-sm break-words">{{ g.prompt }}</p>

            <p v-if="statusHint" class="text-xs text-muted-foreground">{{ statusHint }}</p>

            <div v-if="g.status === 'failed'" class="rounded-md bg-rose-50 p-2 text-xs text-rose-800 dark:bg-rose-950/40 dark:text-rose-300">
                <p class="font-medium">{{ g.error_message ?? 'Generation failed.' }}</p>
                <p v-if="g.error_detail && g.error_detail !== g.error_message" class="mt-0.5 opacity-80">{{ g.error_detail }}</p>
                <p class="mt-1">You were not charged. The held amount was returned to your balance.</p>
            </div>

            <dl class="mt-auto grid grid-cols-2 gap-2 text-xs sm:grid-cols-3">
                <div>
                    <dt class="text-muted-foreground">Estimated (max)</dt>
                    <dd class="font-mono" :title="`$${exactUsd(estimated)}`">{{ formatUsd(estimated) }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Actual</dt>
                    <dd class="font-mono font-semibold" :title="actual !== null ? `$${exactUsd(actual)}` : ''">
                        <template v-if="actual !== null">{{ formatUsd(actual) }}</template>
                        <span v-else class="font-normal text-muted-foreground">pending</span>
                    </dd>
                </div>
                <div v-if="saved !== null && saved > 0n && g.status === 'succeeded'">
                    <dt class="text-muted-foreground">Under estimate by</dt>
                    <dd class="font-mono text-emerald-600 dark:text-emerald-400">{{ formatUsd(saved) }}</dd>
                </div>
            </dl>

            <details v-if="g.usage" class="text-xs text-muted-foreground">
                <summary class="cursor-pointer select-none">Token usage</summary>
                <ul class="mt-1 grid grid-cols-2 gap-x-4 font-mono">
                    <li v-for="(n, k) in g.usage" :key="k">{{ String(k).replace(/_/g, ' ') }}: {{ n.toLocaleString() }}</li>
                </ul>
            </details>
        </div>
    </article>
</template>
