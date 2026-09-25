<script setup>
import PengaturanLayout from '@/Layouts/PengaturanLayout.vue';
import { useForm, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    stages: Array,
});

const totalBobot = computed(() =>
    props.stages.reduce((sum, s) => sum + Number(s.bobot), 0)
);
const isBalanced = computed(() => Math.abs(totalBobot.value - 100) < 0.01);

// ── Edit nama/bobot/warna langsung dari baris (instant save, seperti pola
// inline-edit lain di app ini — bukan modal) ─────────────────────────────
const updateStage = (stage, changes) => {
    const affectsLive = stage.kavlings_count > 0 && ('bobot' in changes);
    if (affectsLive && !confirm(
        `Tahap "${stage.nama}" sedang dipakai ${stage.kavlings_count} kavling — mengubah bobotnya langsung mengubah progress kavling itu. Lanjutkan?`
    )) return;

    router.patch(route('pengaturan.status-bangun.update', stage.id), {
        nama: changes.nama ?? stage.nama,
        bobot: changes.bobot ?? stage.bobot,
        warna: changes.warna ?? stage.warna,
    }, { preserveScroll: true });
};

// ── Reorder ───────────────────────────────────────────────────────────
const canMoveUp = (stage, idx) => idx > 1; // idx 0 = Belum Mulai (selalu pertama), idx 1 tidak bisa naik lewati itu
const canMoveDown = (idx) => idx > 0 && idx < props.stages.length - 1;

const move = (stage, direction) => {
    router.patch(route(`pengaturan.status-bangun.move-${direction}`, stage.id), {}, { preserveScroll: true });
};

// ── Tambah tahap baru ────────────────────────────────────────────────
const palette = ['#f97316', '#3b82f6', '#6366f1', '#a855f7', '#10b981', '#ec4899', '#eab308', '#14b8a6'];
const addForm = useForm({
    nama: '',
    bobot: 0,
    warna: palette[Math.floor(Math.random() * palette.length)],
});

const addStage = () => {
    addForm.post(route('pengaturan.status-bangun.store'), {
        onSuccess: () => addForm.reset('nama', 'bobot'),
        preserveScroll: true,
    });
};

// ── Hapus tahap ──────────────────────────────────────────────────────
const destroyStage = (stage) => {
    const msg = stage.kavlings_count > 0
        ? `Tahap "${stage.nama}" masih dipakai ${stage.kavlings_count} kavling — mereka akan otomatis dipindah ke tahap sebelumnya. Lanjutkan hapus?`
        : `Hapus tahap "${stage.nama}"? Belum ada kavling yang pakai.`;
    if (!confirm(msg)) return;
    router.delete(route('pengaturan.status-bangun.destroy', stage.id), { preserveScroll: true });
};
</script>

<template>
    <PengaturanLayout title="Status Bangun">
        <div>
            <h1 class="text-white font-bold text-xl">Status Bangun</h1>
            <p class="text-slate-400 text-sm mt-0.5">
                Master tahap progress pembangunan kavling — global untuk semua proyek. Tambah/hapus/urutkan tahap
                bebas, atur bobot masing-masing sampai total 100%. Progress unit = total bobot tahap-tahap sebelumnya
                + bobot tahap yang sedang dikerjakan × persen penyelesaian tahap itu (diisi di Proses Bangun). Unit baru dianggap
                siap serah terima kalau sudah di tahap terakhir dan persennya 100%.
            </p>
        </div>

        <!-- Running total banner -->
        <div :class="isBalanced
                ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-400'
                : 'bg-amber-500/10 border-amber-500/20 text-amber-400'"
            class="border rounded-xl px-4 py-3 flex items-center justify-between">
            <span class="text-sm font-medium">
                {{ isBalanced ? '✓ Total bobot: 100%' : `⚠ Total bobot: ${totalBobot}% — ${totalBobot < 100 ? `kurang ${(100 - totalBobot).toFixed(2)}%` : `lebih ${(totalBobot - 100).toFixed(2)}%`}` }}
            </span>
            <span v-if="!isBalanced" class="text-xs opacity-80">Sesuaikan bobot tahap di bawah supaya progress unit akurat.</span>
        </div>

        <!-- Daftar tahap -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-800 text-left text-slate-500 text-xs uppercase tracking-wider">
                        <th class="px-4 py-3 w-10"></th>
                        <th class="px-2 py-3">Nama Tahap</th>
                        <th class="px-2 py-3 w-32">Bobot (%)</th>
                        <th class="px-2 py-3 w-24">Dipakai</th>
                        <th class="px-2 py-3 w-28">Urutan</th>
                        <th class="px-4 py-3 w-16"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    <tr v-for="(stage, idx) in stages" :key="stage.id" class="hover:bg-slate-800/20 transition-colors">
                        <td class="px-4 py-2.5">
                            <input type="color" :value="stage.warna"
                                @change="updateStage(stage, { warna: $event.target.value })"
                                class="w-7 h-7 rounded cursor-pointer bg-transparent border border-slate-700" />
                        </td>
                        <td class="px-2 py-2.5">
                            <div class="flex items-center gap-2">
                                <input :value="stage.nama"
                                    @change="updateStage(stage, { nama: $event.target.value })"
                                    class="w-full px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                <span v-if="stage.is_default" class="flex-shrink-0 px-1.5 py-0.5 bg-slate-700 text-slate-400 text-[10px] rounded-full font-medium">Default</span>
                            </div>
                        </td>
                        <td class="px-2 py-2.5">
                            <div class="relative">
                                <input type="number" min="0" max="100" step="0.5" :value="stage.bobot"
                                    :disabled="stage.is_default"
                                    @change="updateStage(stage, { bobot: $event.target.value })"
                                    class="w-full pl-2.5 pr-6 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 focus:outline-none focus:ring-1 focus:ring-violet-500 disabled:opacity-50 disabled:cursor-not-allowed" />
                                <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-500 text-xs pointer-events-none">%</span>
                            </div>
                        </td>
                        <td class="px-2 py-2.5">
                            <span class="text-slate-400 text-xs">{{ stage.kavlings_count }} unit</span>
                        </td>
                        <td class="px-2 py-2.5">
                            <div class="flex items-center gap-1">
                                <button v-if="!stage.is_default" @click="move(stage, 'up')" :disabled="!canMoveUp(stage, idx)"
                                    class="p-1 text-slate-400 hover:text-violet-400 disabled:opacity-30 disabled:cursor-not-allowed disabled:hover:text-slate-400 rounded transition-colors">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4"><path fill-rule="evenodd" d="M14.77 12.79a.75.75 0 01-1.06-.02L10 8.832 6.29 12.77a.75.75 0 11-1.08-1.04l4.25-4.5a.75.75 0 011.08 0l4.25 4.5a.75.75 0 01-.02 1.06z" clip-rule="evenodd"/></svg>
                                </button>
                                <button v-if="!stage.is_default" @click="move(stage, 'down')" :disabled="!canMoveDown(idx)"
                                    class="p-1 text-slate-400 hover:text-violet-400 disabled:opacity-30 disabled:cursor-not-allowed disabled:hover:text-slate-400 rounded transition-colors">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                                </button>
                            </div>
                        </td>
                        <td class="px-4 py-2.5 text-right">
                            <button v-if="!stage.is_default" @click="destroyStage(stage)"
                                class="p-1.5 text-slate-400 hover:text-rose-400 hover:bg-rose-400/10 rounded-lg transition-colors">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Tambah tahap baru -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
            <h3 class="text-white font-semibold text-sm mb-3">+ Tambah Tahap</h3>
            <div class="flex gap-3">
                <input type="color" v-model="addForm.warna"
                    class="w-10 h-10 rounded cursor-pointer bg-transparent border border-slate-700 flex-shrink-0" />
                <input v-model="addForm.nama" type="text" placeholder="Nama tahap (mis. Pengecatan)"
                    @keyup.enter="addStage"
                    :class="{ 'border-rose-500': addForm.errors.nama }"
                    class="flex-1 px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                <div class="relative w-32">
                    <input v-model="addForm.bobot" type="number" min="0" max="100" step="0.5" placeholder="0"
                        @keyup.enter="addStage"
                        :class="{ 'border-rose-500': addForm.errors.bobot }"
                        class="w-full pl-3 pr-7 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 text-xs pointer-events-none">%</span>
                </div>
                <button @click="addStage" :disabled="addForm.processing || !addForm.nama"
                    class="px-4 py-2 bg-violet-600 hover:bg-violet-500 disabled:opacity-50 text-white text-sm font-medium rounded-lg transition-colors whitespace-nowrap">
                    + Tambah
                </button>
            </div>
            <p class="text-slate-500 text-xs mt-2">Tahap baru ditambahkan di urutan paling akhir — geser posisinya pakai tombol panah kalau perlu.</p>
        </div>
    </PengaturanLayout>
</template>
