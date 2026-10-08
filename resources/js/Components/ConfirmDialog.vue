<script setup>
import { confirmState, tutupKonfirmasi } from '@/Composables/useConfirm';
import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue';

const batalBtn = ref(null);

// Fokus awal di tombol "Batal" untuk aksi berbahaya (Enter tidak langsung menghapus).
watch(() => confirmState.open, async (open) => {
    if (open) {
        await nextTick();
        batalBtn.value?.focus();
    }
});

const onKey = (e) => {
    if (confirmState.open && e.key === 'Escape') tutupKonfirmasi(false);
};
onMounted(() => window.addEventListener('keydown', onKey));
onUnmounted(() => window.removeEventListener('keydown', onKey));
</script>

<template>
    <Teleport to="body">
        <div v-if="confirmState.open" class="fixed inset-0 z-[100] flex items-center justify-center p-4"
            role="dialog" aria-modal="true" data-esc-sendiri :aria-label="confirmState.title">
            <div class="absolute inset-0 bg-black/60" @click="tutupKonfirmasi(false)" />
            <div class="relative w-full max-w-sm rounded-2xl bg-slate-900 border border-slate-700 shadow-2xl p-5">
                <div class="flex items-start gap-3">
                    <div :class="['flex-shrink-0 w-9 h-9 rounded-full flex items-center justify-center text-lg',
                        confirmState.danger ? 'bg-rose-500/15 text-rose-400' : 'bg-violet-500/15 text-violet-400']">
                        {{ confirmState.danger ? '!' : '?' }}
                    </div>
                    <div class="min-w-0">
                        <h2 class="text-white font-semibold text-sm">{{ confirmState.title }}</h2>
                        <p class="mt-1.5 text-slate-300 text-sm whitespace-pre-line break-words">{{ confirmState.message }}</p>
                    </div>
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button ref="batalBtn" type="button" @click="tutupKonfirmasi(false)"
                        class="px-3.5 py-2 rounded-lg text-sm text-slate-300 hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-violet-500">
                        {{ confirmState.cancelText }}
                    </button>
                    <button type="button" @click="tutupKonfirmasi(true)"
                        :class="['px-3.5 py-2 rounded-lg text-sm font-medium text-white focus:outline-none focus:ring-2',
                            confirmState.danger ? 'bg-rose-600 hover:bg-rose-500 focus:ring-rose-400' : 'bg-violet-600 hover:bg-violet-500 focus:ring-violet-400']">
                        {{ confirmState.confirmText }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
