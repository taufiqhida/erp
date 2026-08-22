<script setup>
import { ref, watch, onMounted, nextTick } from 'vue';

const props = defineProps({
    siteplanUrl: { type: String, required: true },
    kavlings: { type: Array, default: () => [] },
    // Map status_jual value -> warna fill
    statusColors: { type: Object, required: true },
    // Map status_bangun value -> warna stroke/ring (opsional)
    statusBangunColors: { type: Object, default: () => ({}) },
    // Kalau true: unit bisa diklik (emit 'select') & selectedId di-highlight.
    // Dipakai di halaman Penjualan (klik unit -> buka detail/booking).
    // Kalau false: murni visual read-only, tanpa modal (dipakai di Manajemen Proyek).
    interactive: { type: Boolean, default: false },
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
        el.style.stroke = props.statusBangunColors[k.status_bangun] ?? 'none';
        el.style.strokeWidth = '2px';
        el.style.transition = 'opacity 0.15s ease';
        el.classList.add('siteplan-svg-unit');
        el.classList.toggle('siteplan-svg-unit-interactive', props.interactive);
        el.classList.toggle('siteplan-svg-unit-selected', props.interactive && String(k.id) === String(props.selectedId));

        // Tooltip native (nomor + status jual + status bangun), tanpa modal.
        let titleEl = el.querySelector(':scope > title');
        if (!titleEl) {
            titleEl = document.createElementNS('http://www.w3.org/2000/svg', 'title');
            el.prepend(titleEl);
        }
        titleEl.textContent = `${k.nomor_lengkap} — ${k.status_jual_label ?? k.status_jual} · ${k.status_bangun_label ?? k.status_bangun}`;

        if (props.interactive) {
            const handler = () => emit('select', k);
            el.addEventListener('click', handler);
            attachedListeners.push({ el, handler });
        }
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
:deep(.siteplan-svg-unit-interactive) {
    cursor: pointer;
}
:deep(.siteplan-svg-unit-interactive:hover) {
    opacity: 0.75;
}
:deep(.siteplan-svg-unit-selected) {
    stroke: #ffffff !important;
    stroke-width: 2px;
}
</style>
