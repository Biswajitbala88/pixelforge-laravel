<script setup lang="ts">
import { computed } from 'vue';
import type { GenerationStatus } from '@/types/generation';

const props = defineProps<{ status: GenerationStatus; retrying?: boolean }>();

const styles: Record<GenerationStatus, string> = {
    submitting: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
    queued: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
    processing: 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300',
    succeeded: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
    failed: 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300',
};

const label = computed(() => (props.status === 'submitting' && props.retrying ? 'retrying' : props.status));
const active = computed(() => ['submitting', 'queued', 'processing'].includes(props.status));
</script>

<template>
    <span :class="['inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium capitalize', styles[status]]">
        <span v-if="active" class="size-1.5 animate-pulse rounded-full bg-current" />
        {{ label }}
    </span>
</template>
