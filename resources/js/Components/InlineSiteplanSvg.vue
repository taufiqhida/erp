<script setup>
import { ref, watch, onMounted, nextTick } from 'vue';

const props = defineProps({
    siteplanUrl: { type: String, required: true },
    kavlings: { type: Array, default: () => [] },
    // Map status_jual value -> warna fill
    statusColors: { type: Object, required: true },
    selectedId: { type: [Number, String], default: null },
});

const emit = defineEmits(['select', 'loaded', 'unmatched']);

const container = ref(null);
const loading = ref(true);
const loadError = ref(false);

// Simpan referensi listener supaya bisa dilepas saat re-render, cegah leak.
let attachedListeners = [];

const clearListeners = () => {
    attachedListeners.forEach(({ el, handler }) => el.removeEventListener('click', handler));
    attachedListeners = [];
};

const applyColorsAndListeners = () => {
    if (!container.value) return;
    clearListeners();

    const unmatched = [];

    props.kavlings.forEach((k) => {
        if (!k.svg_id) return;
        const el = container.value.querySelector(`[id="${CSS.escape(String(k.svg_id))}"]`);
        if (!el) {
            unmatched.push(k.svg_id);
            return;
        }

        el.style.fill = props.statusColors[k.status_jual] ?? '#94a3b8';
        el.style.cursor = 'pointer';
        el.style.transition = 'opacity 0.15s ease';
        el.classList.add('siteplan-svg-unit');
        el.classList.toggle('siteplan-svg-unit-selected', String(k.id) === String(props.selectedId));

        const handler = () => emit('select', k);
        el.addEventListener('click', handler);
        attachedListeners.push({ el, handler });
    });

    if (unmatched.length) {
        emit('unmatched', unmatched);
    }
};

const loadSvg = async () => {
    loading.value = true;
    loadError.value = false;
    clearListeners();

    try {
        const res = await fetch(props.siteplanUrl, { cache: 'force-cache' });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const svgText = await res.text();

        if (!container.value) return;
        container.value.innerHTML = svgText;

        const svgEl = container.value.querySelector('svg');
        if (svgEl) {
            svgEl.style.width = '100%';
            svgEl.style.height = 'auto';
            svgEl.removeAttribute('width');
            svgEl.removeAttribute('height');
        }

        await nextTick();
        applyColorsAndListeners();
        emit('loaded');
    } catch (e) {
        loadError.value = true;
    } finally {
        loading.value = false;
    }
};

onMounted(loadSvg);
watch(() => props.siteplanUrl, loadSvg);
watch(() => props.kavlings, applyColorsAndListeners, { deep: true });
watch(() => props.selectedId, applyColorsAndListeners);
</script>

<template>
    <div class="relative w-full">
        <div v-if="loading" class="flex items-center justify-center py-16 text-slate-500 text-sm">
            Memuat siteplan...
        </div>
        <div v-else-if="loadError" class="flex items-center justify-center py-16 text-rose-400 text-sm">
            Gagal memuat file siteplan.
        </div>
        <div ref="container" class="w-full [&_svg]:block" />
    </div>
</template>

<style scoped>
:deep(.siteplan-svg-unit:hover) {
    opacity: 0.75;
}
:deep(.siteplan-svg-unit-selected) {
    stroke: #ffffff;
    stroke-width: 2px;
}
</style>
