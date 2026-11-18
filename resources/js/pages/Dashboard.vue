<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import BuyCredits from '@/components/BuyCredits.vue';
import GenerationCard from '@/components/generations/GenerationCard.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { exactUsd, formatUsd, toNanos } from '@/lib/money';
import { estimate, type PricingProps } from '@/lib/pricing';
import { ACTIVE_STATUSES, type Generation } from '@/types/generation';

defineOptions({ layout: { breadcrumbs: [{ title: 'Generate', href: '/dashboard' }] } });

const props = defineProps<{
    pricing: PricingProps;
    balance_nanos: string;
    generations: Generation[];
}>();

const MAX_REFS = 4;
const MAX_PROMPT = 4000;

const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [] as string[],
});

const validRefs = computed(() => form.reference_images.filter((u) => u.trim() !== ''));
const breakdown = computed(() => estimate(props.pricing, form.prompt, validRefs.value.length, form.size, form.quality));
const balance = computed(() => toNanos(props.balance_nanos));
const insufficient = computed(() => breakdown.value !== null && balance.value < breakdown.value.userNanos);
const promptLength = computed(() => [...form.prompt].length);
const canSubmit = computed(() => !form.processing && form.prompt.trim() !== '' && promptLength.value <= MAX_PROMPT && !insufficient.value);

const refErrors = computed(() =>
    Object.entries(form.errors as Record<string, string>)
        .filter(([k]) => k.startsWith('reference_images'))
        .map(([, v]) => v),
);

function addRef() {
    if (form.reference_images.length < MAX_REFS) form.reference_images.push('');
}

function submit() {
    form.post('/generations', {
        preserveScroll: true,
        onSuccess: () => form.reset('prompt', 'reference_images'),
    });
}

// ---- Live status: poll every 3s while anything is in flight ----
const hasActive = computed(() => props.generations.some((g) => ACTIVE_STATUSES.includes(g.status)));
let timer: ReturnType<typeof setInterval> | null = null;
const polling = ref(false);

function startPolling() {
    if (timer) return;
    polling.value = true;
    timer = setInterval(() => {
        router.reload({ only: ['generations', 'balance_nanos'] });
    }, 3000);
}
function stopPolling() {
    if (timer) clearInterval(timer);
    timer = null;
    polling.value = false;
}

watch(hasActive, (active) => (active ? startPolling() : stopPolling()), { immediate: true });
onBeforeUnmount(stopPolling);

const sizeLabels: Record<string, string> = { '1024x1024': 'Square 1024×1024', '1536x1024': 'Landscape 1536×1024', '1024x1536': 'Portrait 1024×1536' };
const selectClass = 'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none dark:bg-input/30';
</script>

<template>
    <Head title="Generate" />

    <div class="flex flex-1 flex-col gap-6 p-4 lg:flex-row lg:items-start">
        <!-- Generate form -->
        <section class="w-full space-y-4 lg:sticky lg:top-4 lg:max-w-md">
            <div class="flex items-center justify-between rounded-xl border p-4">
                <div>
                    <p class="text-xs text-muted-foreground">Credit balance</p>
                    <p class="font-mono text-xl font-semibold" :title="`$${exactUsd(balance)}`">{{ formatUsd(balance, { decimals: 4 }) }}</p>
                </div>
                <BuyCredits />
            </div>

            <form class="space-y-4 rounded-xl border p-4" @submit.prevent="submit">
                <div>
                    <div class="mb-1 flex items-baseline justify-between">
                        <label for="prompt" class="text-sm font-medium">Prompt</label>
                        <span :class="['text-xs', promptLength > MAX_PROMPT ? 'text-rose-600' : 'text-muted-foreground']">
                            {{ promptLength.toLocaleString() }} / {{ MAX_PROMPT.toLocaleString() }}
                        </span>
                    </div>
                    <textarea
                        id="prompt"
                        v-model="form.prompt"
                        rows="5"
                        placeholder="A lighthouse on a cliff at dusk, oil painting…"
                        class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none dark:bg-input/30"
                    />
                    <p class="mt-1 text-xs text-muted-foreground">Test keywords: <code>[fail]</code> <code>[slow]</code> <code>[duplicate]</code></p>
                    <InputError :message="form.errors.prompt" />
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="size" class="mb-1 block text-sm font-medium">Size</label>
                        <select id="size" v-model="form.size" :class="selectClass">
                            <option v-for="s in pricing.sizes" :key="s" :value="s">{{ sizeLabels[s] ?? s }}</option>
                        </select>
                        <InputError :message="form.errors.size" />
                    </div>
                    <div>
                        <label for="quality" class="mb-1 block text-sm font-medium">Quality</label>
                        <select id="quality" v-model="form.quality" :class="selectClass">
                            <option v-for="q in pricing.qualities" :key="q" :value="q" class="capitalize">{{ q }}</option>
                        </select>
                        <InputError :message="form.errors.quality" />
                    </div>
                </div>

                <div>
                    <div class="mb-1 flex items-center justify-between">
                        <span class="text-sm font-medium">Reference images <span class="font-normal text-muted-foreground">(optional)</span></span>
                        <button type="button" class="text-xs font-medium text-primary underline-offset-4 hover:underline disabled:opacity-40" :disabled="form.reference_images.length >= MAX_REFS" @click="addRef">
                            + Add URL
                        </button>
                    </div>
                    <div v-for="(_, i) in form.reference_images" :key="i" class="mb-2 flex gap-2">
                        <input
                            v-model="form.reference_images[i]"
                            type="url"
                            placeholder="https://…"
                            class="h-9 min-w-0 flex-1 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs dark:bg-input/30"
                        />
                        <button type="button" class="px-2 text-muted-foreground hover:text-foreground" aria-label="Remove" @click="form.reference_images.splice(i, 1)">✕</button>
                    </div>
                    <p v-if="!form.reference_images.length" class="text-xs text-muted-foreground">Up to {{ MAX_REFS }}. Each adds {{ pricing.image_tokens_per_reference }} input image tokens.</p>
                    <InputError v-for="(e, i) in refErrors" :key="i" :message="e" />
                </div>

                <!-- Live estimate -->
                <div class="rounded-lg bg-muted/60 p-3">
                    <div class="flex items-baseline justify-between">
                        <span class="text-sm font-medium">Estimated max cost</span>
                        <span class="font-mono text-2xl font-semibold" :title="breakdown ? `$${exactUsd(breakdown.userNanos)}` : ''">
                            {{ breakdown ? formatUsd(breakdown.userNanos) : '—' }}
                        </span>
                    </div>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Worst-case price. You pay the <em>actual</em> token cost + 25%, which is usually lower. The estimate is held from your balance and the difference is returned when the image finishes. Failed generations are free.
                    </p>
                    <details v-if="breakdown" class="mt-2 text-xs text-muted-foreground">
                        <summary class="cursor-pointer select-none">Breakdown</summary>
                        <ul class="mt-1 space-y-0.5 font-mono">
                            <li>text in: {{ breakdown.tokens.input_text_tokens.toLocaleString() }} tok</li>
                            <li>image in: {{ breakdown.tokens.input_image_tokens.toLocaleString() }} tok</li>
                            <li>output: {{ breakdown.tokens.output_tokens.toLocaleString() }} tok</li>
                            <li>reasoning (max): {{ breakdown.tokens.reasoning_tokens.toLocaleString() }} tok</li>
                            <li>provider cost: ${{ exactUsd(breakdown.providerNanos) }}</li>
                            <li>with 25% markup: ${{ exactUsd(breakdown.userNanos) }}</li>
                        </ul>
                        <p v-if="pricing.source !== 'live'" class="mt-1 text-amber-600">
                            Pixelforge pricing is unavailable right now, so this uses {{ pricing.source === 'cached' ? 'the last known' : 'the published default' }} rates.
                        </p>
                    </details>
                </div>

                <p v-if="insufficient" class="text-sm text-rose-600 dark:text-rose-400">Your balance doesn't cover this estimate. Buy credits to continue.</p>
                <InputError :message="(form.errors as Record<string, string>).balance" />

                <Button type="submit" class="w-full" :disabled="!canSubmit">
                    {{ form.processing ? 'Submitting…' : 'Generate' }}
                </Button>
            </form>
        </section>

        <!-- History -->
        <section class="min-w-0 flex-1 space-y-3">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold">Your generations</h2>
                <span v-if="polling" class="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <span class="size-1.5 animate-pulse rounded-full bg-emerald-500" /> Live
                </span>
            </div>
            <p v-if="!generations.length" class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                No generations yet. Your images will appear here.
            </p>
            <GenerationCard v-for="g in generations" :key="g.id" :generation="g" />
        </section>
    </div>
</template>
