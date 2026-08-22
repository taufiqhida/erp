<script setup>
import { ref, computed, nextTick } from 'vue';

const props = defineProps({
    modelValue: { type: [Number, String], default: null },
    items: { type: Array, default: () => [] },
    // Field pada tiap item yang dicari saat mengetik (mis. ['nama', 'no_hp', 'nik'])
    searchKeys: { type: Array, required: true },
    // Field pada tiap item yang jadi label utama (mis. 'nama')
    labelKey: { type: String, default: 'nama' },
    placeholder: { type: String, default: '-- pilih --' },
    // Fungsi opsional untuk teks tambahan di sebelah kanan tiap opsi
    optionHint: { type: Function, default: null },
});

const emit = defineEmits(['update:modelValue']);

const query = ref('');
const open = ref(false);
const inputEl = ref(null);

const selected = computed(() =>
    props.items.find(item => item.id === props.modelValue) ?? null
);

const searchableText = (item) =>
    props.searchKeys.map(key => item[key]).filter(Boolean).join(' ').toLowerCase();

const filtered = computed(() => {
    const q = query.value.trim().toLowerCase();
    const list = !q ? props.items : props.items.filter(item => searchableText(item).includes(q));
    return list.slice(0, 50);
});

const openDropdown = () => {
    open.value = true;
    query.value = '';
    nextTick(() => inputEl.value?.focus());
};

const pick = (item) => {
    emit('update:modelValue', item.id);
    open.value = false;
    query.value = '';
};

const clearSelection = () => {
    emit('update:modelValue', null);
};

// Delay penutupan supaya click pada opsi sempat ke-handle sebelum blur menutup dropdown
const onBlur = () => {
    setTimeout(() => { open.value = false; }, 150);
};
</script>

<template>
    <div class="relative">
        <!-- Tampilan terpilih (klik untuk buka pencarian) -->
        <button v-if="!open" type="button" @click="openDropdown"
            class="w-full flex items-center justify-between px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-sm text-left focus:outline-none focus:ring-1 focus:ring-violet-500">
            <span :class="selected ? 'text-slate-200' : 'text-slate-500'">
                {{ selected ? selected[labelKey] : placeholder }}
            </span>
            <span class="flex items-center gap-1.5 flex-shrink-0">
                <svg v-if="selected" @click.stop="clearSelection" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3.5 h-3.5 text-slate-500 hover:text-slate-300"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"/></svg>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3.5 h-3.5 text-slate-500"><path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 011.06 0L10 11.94l3.72-3.72a.75.75 0 111.06 1.06l-4.25 4.25a.75.75 0 01-1.06 0L5.22 9.28a.75.75 0 010-1.06z" clip-rule="evenodd"/></svg>
            </span>
        </button>

        <!-- Search input (saat terbuka) -->
        <input v-else ref="inputEl" v-model="query" @blur="onBlur" type="text"
            placeholder="Ketik untuk mencari..."
            class="w-full px-3 py-2 bg-slate-800 border border-violet-500 rounded-lg text-slate-200 text-sm focus:outline-none" />

        <!-- Dropdown hasil pencarian -->
        <div v-if="open" class="absolute z-20 mt-1 w-full max-h-64 overflow-y-auto bg-slate-800 border border-slate-700 rounded-lg shadow-xl">
            <button v-for="item in filtered" :key="item.id" type="button" @mousedown.prevent="pick(item)"
                class="w-full flex items-center justify-between px-3 py-2 text-left text-sm hover:bg-slate-700 transition-colors"
                :class="item.id === modelValue ? 'bg-violet-600/20 text-violet-300' : 'text-slate-200'">
                <span>{{ item[labelKey] }}</span>
                <span class="text-slate-500 text-xs">{{ optionHint ? optionHint(item) : '' }}</span>
            </button>
            <div v-if="!filtered.length" class="px-3 py-3 text-slate-500 text-xs text-center">
                Tidak ada yang cocok.
            </div>
        </div>
    </div>
</template>
