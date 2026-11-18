<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';

const open = ref(false);
const form = useForm({ amount_usd: 5 });

function buy(amount: number) {
    form.amount_usd = amount;
    form.post('/credits', { preserveScroll: true, onSuccess: () => (open.value = false) });
}
</script>

<template>
    <div class="relative">
        <Button size="sm" variant="outline" @click="open = !open">Buy credits</Button>
        <div v-if="open" class="absolute right-0 z-20 mt-2 w-56 rounded-lg border bg-popover p-3 shadow-lg">
            <p class="mb-2 text-xs text-muted-foreground">Demo checkout. No real payment is taken.</p>
            <div class="flex gap-2">
                <Button size="sm" class="flex-1" :disabled="form.processing" @click="buy(5)">+ $5.00</Button>
                <Button size="sm" class="flex-1" :disabled="form.processing" @click="buy(10)">+ $10.00</Button>
            </div>
        </div>
    </div>
</template>
