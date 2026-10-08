<script setup>
import { onMounted, ref } from 'vue';

const model = defineModel({
    type: String,
    required: true,
});

const input = ref(null);

onMounted(() => {
    if (input.value.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <input
        class="rounded-md border-slate-700 bg-slate-800 text-slate-100 placeholder-slate-500 shadow-sm focus:border-violet-500 focus:ring-violet-500"
        v-model="model"
        ref="input"
    />
</template>
