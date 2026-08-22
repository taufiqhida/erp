<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';

const props = defineProps({
    project:            Object,
    kavlings:           Object,
    filters:            Object,
    statusJualOptions:  Array,
    statusBangunOptions: Array,
    klusterOptions:     Array,
    blokOptions:        Array,
    tipeOptions:        Array,
});

const page = usePage();
const isAdmin = computed(() =>
    page.props.auth?.user?.roles?.some(r => ['superadmin', 'manajer'].includes(r))
);
const canUpdateStatusBangun = computed(() =>
    page.props.auth?.user?.permissions?.includes('update status bangun')
);

const statusJual    = ref(props.filters?.status_jual ?? '');
const statusBangun  = ref(props.filters?.status_bangun ?? '');
const blok          = ref(props.filters?.blok ?? '');
const kluster       = ref(props.filters?.kluster ?? '');
const tipeUnit      = ref(props.filters?.tipe_unit ?? '');

const applyFilter = () => {
    router.get(
        route('projects.kavlings.index', props.project.id),
        {
            status_jual:   statusJual.value || undefined,
            status_bangun: statusBangun.value || undefined,
            blok:          blok.value || undefined,
            kluster:       kluster.value || undefined,
            tipe_unit:     tipeUnit.value || undefined,
        },
        { preserveState: true, replace: true }
    );
};

watch([statusJual, statusBangun, blok, kluster, tipeUnit], applyFilter);

const activeFilterCount = () =>
    [statusJual, statusBangun, blok, kluster, tipeUnit].filter(r => r.value).length;

const resetFilters = () => {
    statusJual.value = '';
    statusBangun.value = '';
    blok.value = '';
    kluster.value = '';
    tipeUnit.value = '';
};

const formatRupiah = (n) => n ? 'Rp ' + new Intl.NumberFormat('id-ID').format(n) : '-';

const statusBadgeClass = (color) => ({
    green:  'bg-emerald-500/15 text-emerald-400 ring-1 ring-emerald-500/30',
    yellow: 'bg-yellow-500/15 text-yellow-400 ring-1 ring-yellow-500/30',
    blue:   'bg-blue-500/15 text-blue-400 ring-1 ring-blue-500/30',
    red:    'bg-rose-500/15 text-rose-400 ring-1 ring-rose-500/30',
    orange: 'bg-orange-500/15 text-orange-400 ring-1 ring-orange-500/30',
    gray:   'bg-slate-700/50 text-slate-400 ring-1 ring-slate-600',
    indigo: 'bg-indigo-500/15 text-indigo-400 ring-1 ring-indigo-500/30',
    purple: 'bg-purple-500/15 text-purple-400 ring-1 ring-purple-500/30',
}[color] ?? 'bg-slate-700/50 text-slate-400 ring-1 ring-slate-600');

// ── Toggle ketersediaan (Tersedia / Tidak Tersedia) ──────────────────────
const toggleStatusJual = (k) => {
    const next = k.status_jual === 'available' ? 'hold' : 'available';
    useForm({ status_jual: next }).patch(route('kavlings.status-jual', k.id), { preserveScroll: true });
};

// ── Update status bangun langsung dari tabel ─────────────────────────────
const updateStatusBangunInline = (k, value) => {
    useForm({ status_bangun: value }).patch(route('kavlings.status-bangun', k.id), { preserveScroll: true });
};

// ── Edit Kavling (info umum saja) ────────────────────────────────────────
const showEditModal = ref(false);
const editingKavling = ref(null);
const kavlingEditForm = useForm({
    kluster: '', blok: '', nomor_kavling: '', tipe_unit: '', luas_tanah: '', luas_bangunan: '',
    harga: '', keterangan: '', catatan: '',
});

const openEditKavling = (k) => {
    kavlingEditForm.clearErrors();
    kavlingEditForm.kluster = k.kluster ?? '';
    kavlingEditForm.blok = k.blok ?? '';
    kavlingEditForm.nomor_kavling = k.nomor_kavling ?? '';
    kavlingEditForm.tipe_unit = k.tipe_unit ?? '';
    kavlingEditForm.luas_tanah = k.luas_tanah ?? '';
    kavlingEditForm.luas_bangunan = k.luas_bangunan ?? '';
    kavlingEditForm.harga = k.harga ?? '';
    kavlingEditForm.keterangan = k.keterangan ?? '';
    kavlingEditForm.catatan = k.catatan ?? '';
    editingKavling.value = k;
    showEditModal.value = true;
};

const submitEditKavling = () => {
    kavlingEditForm.put(route('kavlings.update', editingKavling.value.id), {
        onSuccess: () => { showEditModal.value = false; },
    });
};
</script>

<template>
    <Head :title="`Unit – ${project.nama}`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2 text-slate-400 text-sm">
                <Link :href="route('beranda')" class="hover:text-slate-200 transition-colors">Proyek</Link>
                <span>/</span>
                <Link :href="route('projects.show', project.id)" class="hover:text-slate-200 transition-colors">{{ project.nama }}</Link>
                <span>/</span>
                <span class="text-slate-200 font-medium">Semua Unit</span>
            </div>
        </template>

        <div class="p-6 space-y-5">
            <div>
                <h1 class="text-white font-bold text-xl">Semua Unit – {{ project.nama }}</h1>
                <p class="text-slate-400 text-sm mt-0.5">{{ project.kode }}</p>
            </div>

            <!-- Multi-Filter -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-3 flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center gap-1.5 text-slate-500 text-xs font-medium mr-1">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3c2.755 0 5.455.232 8.083.678.533.09.917.556.917 1.096v1.044a2.25 2.25 0 01-.659 1.591l-5.432 5.432a2.25 2.25 0 00-.659 1.591v2.927a2.25 2.25 0 01-1.244 2.013L9.75 21v-6.568a2.25 2.25 0 00-.659-1.591L3.659 7.409A2.25 2.25 0 013 5.818V4.774c0-.54.384-1.006.917-1.096A48.32 48.32 0 0112 3z" /></svg>
                    Filter
                </span>
                <div v-for="(sel, idx) in [
                        { model: kluster, options: klusterOptions, placeholder: 'Semua Kluster' },
                        { model: blok, options: blokOptions, placeholder: 'Semua Blok' },
                        { model: tipeUnit, options: tipeOptions, placeholder: 'Semua Tipe' },
                    ]" :key="idx" class="relative">
                    <select v-model="sel.model.value"
                        class="appearance-none pl-2.5 pr-7 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500 cursor-pointer">
                        <option value="">{{ sel.placeholder }}</option>
                        <option v-for="v in sel.options" :key="v" :value="v">{{ v }}</option>
                    </select>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3.5 h-3.5 text-slate-500 absolute right-2 top-1/2 -translate-y-1/2 pointer-events-none"><path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 011.06 0L10 11.94l3.72-3.72a.75.75 0 111.06 1.06l-4.25 4.25a.75.75 0 01-1.06 0L5.22 9.28a.75.75 0 010-1.06z" clip-rule="evenodd"/></svg>
                </div>
                <div class="relative">
                    <select v-model="statusJual"
                        class="appearance-none pl-2.5 pr-7 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500 cursor-pointer">
                        <option value="">Semua Status Jual</option>
                        <option v-for="opt in statusJualOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                    </select>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3.5 h-3.5 text-slate-500 absolute right-2 top-1/2 -translate-y-1/2 pointer-events-none"><path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 011.06 0L10 11.94l3.72-3.72a.75.75 0 111.06 1.06l-4.25 4.25a.75.75 0 01-1.06 0L5.22 9.28a.75.75 0 010-1.06z" clip-rule="evenodd"/></svg>
                </div>
                <div class="relative">
                    <select v-model="statusBangun"
                        class="appearance-none pl-2.5 pr-7 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500 cursor-pointer">
                        <option value="">Semua Status Bangun</option>
                        <option v-for="opt in statusBangunOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                    </select>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3.5 h-3.5 text-slate-500 absolute right-2 top-1/2 -translate-y-1/2 pointer-events-none"><path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 011.06 0L10 11.94l3.72-3.72a.75.75 0 111.06 1.06l-4.25 4.25a.75.75 0 01-1.06 0L5.22 9.28a.75.75 0 010-1.06z" clip-rule="evenodd"/></svg>
                </div>
                <button v-if="activeFilterCount() > 0" @click="resetFilters"
                    class="px-2.5 py-1.5 text-slate-400 hover:text-slate-200 text-xs rounded-lg transition-colors">
                    ✕ Reset ({{ activeFilterCount() }})
                </button>
                <span class="text-slate-500 text-xs ml-auto">{{ kavlings.total }} unit</span>
            </div>

            <!-- Table -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-800">
                                <th class="text-left px-5 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Kluster</th>
                                <th class="text-left px-4 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Kavling</th>
                                <th class="text-left px-4 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Tipe</th>
                                <th class="text-left px-4 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Luas</th>
                                <th class="text-left px-4 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Harga</th>
                                <th class="text-left px-4 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Status Jual</th>
                                <th class="text-left px-4 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Status Bangun</th>
                                <th class="text-left px-4 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Konsumen</th>
                                <th v-if="isAdmin" class="text-right px-5 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60">
                            <tr v-if="!kavlings.data.length">
                                <td :colspan="isAdmin ? 9 : 8" class="text-center py-12 text-slate-500">Tidak ada unit yang cocok dengan filter.</td>
                            </tr>
                            <tr v-for="k in kavlings.data" :key="k.id" class="hover:bg-slate-800/20 transition-colors">
                                <td class="px-5 py-3.5 text-slate-400 text-xs">{{ k.kluster ?? '-' }}</td>
                                <td class="px-4 py-3.5 text-slate-200 font-medium">{{ k.nomor_lengkap }}</td>
                                <td class="px-4 py-3.5 text-slate-400 text-xs">{{ k.tipe_unit ?? '-' }}</td>
                                <td class="px-4 py-3.5 text-slate-400 text-xs">
                                    <div v-if="k.luas_tanah">T: {{ k.luas_tanah }} m²</div>
                                    <div v-if="k.luas_bangunan" class="text-slate-500">B: {{ k.luas_bangunan }} m²</div>
                                </td>
                                <td class="px-4 py-3.5 text-slate-300 text-xs font-mono">{{ formatRupiah(k.harga) }}</td>
                                <td class="px-4 py-3.5">
                                    <button v-if="isAdmin && ['available', 'hold'].includes(k.status_jual)"
                                        @click="toggleStatusJual(k)"
                                        :title="`Klik untuk ubah jadi ${k.status_jual === 'available' ? 'Tidak Tersedia' : 'Tersedia'}`"
                                        :class="statusBadgeClass(k.status_jual_color)" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium hover:opacity-75 transition-opacity cursor-pointer">
                                        {{ k.status_jual_label }}
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3 h-3"><path fill-rule="evenodd" d="M4 10a.75.75 0 01.75-.75h10.638L11.29 5.29a.75.75 0 111.06-1.06l5.5 5.5a.75.75 0 010 1.06l-5.5 5.5a.75.75 0 11-1.06-1.06l4.098-4.098H4.75A.75.75 0 014 10z" clip-rule="evenodd"/></svg>
                                    </button>
                                    <span v-else :class="statusBadgeClass(k.status_jual_color)" class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium">
                                        {{ k.status_jual_label }}
                                    </span>
                                </td>
                                <td class="px-4 py-3.5">
                                    <div class="flex items-center gap-2">
                                        <div class="w-12 h-1 bg-slate-800 rounded-full overflow-hidden flex-shrink-0">
                                            <div class="h-full bg-gradient-to-r from-violet-500 to-indigo-500 rounded-full" :style="{ width: k.progress_bangun + '%' }"/>
                                        </div>
                                        <select v-if="canUpdateStatusBangun"
                                            :value="k.status_bangun"
                                            @change="updateStatusBangunInline(k, $event.target.value)"
                                            class="px-2 py-1 bg-slate-800 border border-slate-700 rounded-md text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500 cursor-pointer">
                                            <option v-for="opt in statusBangunOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                        </select>
                                        <span v-else class="text-slate-400 text-xs">{{ k.status_bangun_label }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3.5 text-slate-400 text-xs">{{ k.konsumen_nama ?? '-' }}</td>
                                <td v-if="isAdmin" class="px-5 py-3.5 text-right">
                                    <button @click="openEditKavling(k)"
                                            class="inline-flex items-center gap-1.5 px-2.5 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs rounded-lg transition-colors">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                                        Edit
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pagination -->
            <div v-if="kavlings.last_page > 1" class="flex justify-center gap-1">
                <Link
                    v-for="link in kavlings.links"
                    :key="link.label"
                    :href="link.url ?? '#'"
                    v-html="link.label"
                    :class="[
                        'px-3 py-1.5 text-xs rounded-md transition-colors',
                        link.active ? 'bg-violet-600 text-white' : 'text-slate-400 hover:bg-slate-800 bg-slate-900',
                        !link.url ? 'opacity-40 pointer-events-none' : ''
                    ]"
                />
            </div>
        </div>

        <!-- ═══ MODAL: EDIT KAVLING (info umum saja) ═══════════════════════════ -->
        <Teleport to="body">
            <div v-if="showEditModal && editingKavling" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" @click="showEditModal = false"/>
                <div class="relative bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-md shadow-2xl">
                    <div class="flex items-center justify-between p-5 border-b border-slate-800">
                        <div>
                            <h3 class="text-white font-semibold">Edit Kavling {{ editingKavling.nomor_lengkap }}</h3>
                            <p class="text-slate-500 text-xs mt-0.5">Status jual & konsumen tidak diubah dari sini</p>
                        </div>
                        <button @click="showEditModal = false" class="text-slate-500 hover:text-slate-300">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"/></svg>
                        </button>
                    </div>
                    <form @submit.prevent="submitEditKavling" class="p-5 space-y-4">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-slate-400 text-xs mb-1.5">Kluster</label>
                                <input v-model="kavlingEditForm.kluster" type="text" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"/>
                            </div>
                            <div>
                                <label class="block text-slate-400 text-xs mb-1.5">Blok</label>
                                <input v-model="kavlingEditForm.blok" type="text" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"/>
                            </div>
                            <div class="col-span-2">
                                <label class="block text-slate-400 text-xs mb-1.5">Nomor Kavling <span class="text-rose-400">*</span></label>
                                <input v-model="kavlingEditForm.nomor_kavling" type="text" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" :class="{ 'border-rose-500': kavlingEditForm.errors.nomor_kavling }"/>
                                <p v-if="kavlingEditForm.errors.nomor_kavling" class="text-rose-400 text-xs mt-1">{{ kavlingEditForm.errors.nomor_kavling }}</p>
                            </div>
                            <div class="col-span-2">
                                <label class="block text-slate-400 text-xs mb-1.5">Tipe Unit</label>
                                <input v-model="kavlingEditForm.tipe_unit" type="text" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"/>
                            </div>
                            <div>
                                <label class="block text-slate-400 text-xs mb-1.5">Luas Tanah (m²)</label>
                                <input v-model="kavlingEditForm.luas_tanah" type="number" step="0.01" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"/>
                            </div>
                            <div>
                                <label class="block text-slate-400 text-xs mb-1.5">Luas Bangunan (m²)</label>
                                <input v-model="kavlingEditForm.luas_bangunan" type="number" step="0.01" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"/>
                            </div>
                            <div class="col-span-2">
                                <label class="block text-slate-400 text-xs mb-1.5">Harga (Rp)</label>
                                <input v-model="kavlingEditForm.harga" type="number" step="1000000" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"/>
                            </div>
                            <div class="col-span-2">
                                <label class="block text-slate-400 text-xs mb-1.5">Keterangan</label>
                                <input v-model="kavlingEditForm.keterangan" type="text" placeholder="Hook, strategis, dll" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"/>
                            </div>
                            <div class="col-span-2">
                                <label class="block text-slate-400 text-xs mb-1.5">Catatan</label>
                                <textarea v-model="kavlingEditForm.catatan" rows="2" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500 resize-none"/>
                            </div>
                        </div>
                        <div class="flex justify-end gap-3">
                            <button type="button" @click="showEditModal = false" class="px-4 py-2.5 text-slate-400 text-sm">Batal</button>
                            <button type="submit" :disabled="kavlingEditForm.processing" class="px-4 py-2.5 bg-violet-600 hover:bg-violet-500 text-white text-sm font-medium rounded-lg transition-colors">
                                {{ kavlingEditForm.processing ? 'Menyimpan...' : 'Simpan Perubahan' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>
