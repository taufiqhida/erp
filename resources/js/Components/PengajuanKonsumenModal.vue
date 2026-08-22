<script setup>
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import KavlingSearchSelect from '@/Components/KavlingSearchSelect.vue';

const props = defineProps({
    show:              { type: Boolean, default: false },
    type:              { type: String, required: true }, // 'cancellation' | 'unit_swap'
    kavlingId:         { type: [Number, String], required: true },
    kavlingKonsumenId: { type: [Number, String], required: true },
    projectId:         { type: [Number, String], required: true },
});

const emit = defineEmits(['close', 'success']);

const isSwap = computed(() => props.type === 'unit_swap');
const title = computed(() => isSwap.value ? '🔄 Ajukan Tukar Unit' : '🚫 Ajukan Batal');

const availableKavlings = ref([]);
const loadingKavlings = ref(false);

const form = useForm({
    type:                props.type,
    kavling_id:          props.kavlingId,
    kavling_konsumen_id: props.kavlingKonsumenId,
    kavling_baru_id:     '',
    alasan:              '',
});

watch(() => props.show, (val) => {
    if (!val) return;
    form.reset();
    form.type = props.type;
    form.kavling_id = props.kavlingId;
    form.kavling_konsumen_id = props.kavlingKonsumenId;

    if (isSwap.value) {
        loadingKavlings.value = true;
        window.axios.get(route('bookings.available-kavlings', props.projectId))
            .then(res => { availableKavlings.value = res.data.filter(k => k.id !== Number(props.kavlingId)); })
            .finally(() => { loadingKavlings.value = false; });
    }
});

const submit = () => {
    form.post(route('cancellation-requests.store'), {
        preserveScroll: true,
        onSuccess: () => emit('success'),
    });
};

const close = () => emit('close');
</script>

<template>
    <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" @click.self="close">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 w-full max-w-md">
            <h2 class="text-slate-200 font-semibold text-sm mb-4">{{ title }}</h2>

            <div v-if="isSwap" class="mb-4">
                <label class="block text-slate-400 text-xs mb-1.5">Unit Tujuan</label>
                <KavlingSearchSelect
                    v-model="form.kavling_baru_id"
                    :kavlings="availableKavlings"
                    placeholder="-- pilih unit tujuan --"
                />
                <p v-if="loadingKavlings" class="text-slate-500 text-xs mt-1">Memuat unit tersedia...</p>
                <p v-else-if="!availableKavlings.length" class="text-amber-400 text-xs mt-1">Tidak ada unit lain yang tersedia di proyek ini.</p>
                <p v-if="form.errors.kavling_baru_id" class="text-rose-400 text-xs mt-1">{{ form.errors.kavling_baru_id }}</p>
            </div>

            <div class="mb-4">
                <label class="block text-slate-400 text-xs mb-1.5">Alasan</label>
                <textarea v-model="form.alasan" rows="3"
                    :placeholder="isSwap ? 'Alasan tukar unit (min. 10 karakter)' : 'Alasan pembatalan (min. 10 karakter)'"
                    class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"></textarea>
                <p v-if="form.errors.alasan" class="text-rose-400 text-xs mt-1">{{ form.errors.alasan }}</p>
            </div>

            <p class="text-slate-500 text-xs mb-4">Pengajuan ini akan menunggu review &amp; persetujuan manajer di menu Pembatalan.</p>

            <div class="flex items-center justify-end gap-2">
                <button type="button" @click="close"
                    class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-300 text-sm rounded-lg transition-colors border border-slate-700">
                    Batal
                </button>
                <button type="button" @click="submit" :disabled="form.processing || (isSwap && !form.kavling_baru_id)"
                    class="px-3 py-1.5 bg-violet-600 hover:bg-violet-500 disabled:opacity-40 disabled:cursor-not-allowed text-white text-sm font-medium rounded-lg transition-colors">
                    {{ form.processing ? 'Mengirim...' : 'Kirim Pengajuan' }}
                </button>
            </div>
        </div>
    </div>
</template>
