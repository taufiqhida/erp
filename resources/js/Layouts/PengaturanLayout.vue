<script setup>
import BerandaLayout from '@/Layouts/BerandaLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps({
    title: { type: String, required: true },
});

// Tiap item butuh permission spesifik (lihat RolesAndPermissionsSeeder) —
// beda role cuma bisa kelola sebagian master data, jadi menu ini difilter
// biar tidak nampilin link ke halaman yang bakal 403 kalau diklik.
const navGroups = [
    {
        label: 'Identitas & Branding',
        items: [
            { label: 'Pengumuman Login', route: 'pengaturan.pengumuman', permission: 'manage system settings' },
            { label: 'Profil Developer', route: 'pengaturan.profil-developer', permission: 'manage system settings' },
            { label: 'Template Surat',   route: 'pengaturan.surat-templates', permission: 'manage system settings' },
        ],
    },
    {
        label: 'Preset Transaksi Penjualan',
        items: [
            { label: 'Template Pemberkasan', route: 'pengaturan.dokumen-templates', permission: 'manage system settings' },
            { label: 'Biaya Tambahan',       route: 'pengaturan.biaya-tambahan', permission: 'manage system settings' },
            { label: 'Promo',                route: 'pengaturan.promo', permission: 'manage system settings' },
            { label: 'Skema DP',             route: 'pengaturan.skema-dp', permission: 'manage system settings' },
            { label: 'Dana Jaminan & SBUM',  route: 'pengaturan.dajam-sbum', permission: 'manage dajam sbum preset' },
            { label: 'Program All In',       route: 'pengaturan.program-all-in', permission: 'manage program all in' },
            { label: 'Warna Status',         route: 'pengaturan.status-colors', permission: 'manage system settings' },
        ],
    },
    {
        label: 'Master Data Konsumen',
        items: [
            { label: 'Sumber Lead',       route: 'pengaturan.sumber-lead', permission: 'manage system settings' },
            { label: 'Bank Rekanan KPR',  route: 'pengaturan.bank-rekanan', permission: 'manage bank rekanan' },
            { label: 'Notaris',           route: 'pengaturan.notaris', permission: 'manage notaris' },
        ],
    },
    {
        label: 'Tim Penjualan',
        items: [
            { label: 'Sales / Agent', route: 'pengaturan.sales-agents', permission: 'manage sales agent' },
        ],
    },
    {
        label: 'Konstruksi',
        items: [
            { label: 'Status Bangun', route: 'pengaturan.status-bangun', permission: 'manage status bangun master' },
            { label: 'Kontraktor',    route: 'pengaturan.kontraktor', permission: 'manage kontraktor' },
        ],
    },
];

const permissions = computed(() => usePage().props.auth.user?.permissions ?? []);
const visibleNavGroups = computed(() => navGroups
    .map(g => ({ ...g, items: g.items.filter(i => permissions.value.includes(i.permission)) }))
    .filter(g => g.items.length)
);

const isActive = (routeName) => route().current(routeName) || route().current(`${routeName}.*`);
</script>

<template>
    <Head :title="`Pengaturan – ${title}`" />
    <BerandaLayout>
        <template #header>
            <div class="flex items-center gap-2 text-slate-400 text-sm">
                <Link v-if="visibleNavGroups[0]" :href="route(visibleNavGroups[0].items[0].route)" class="hover:text-slate-200 transition-colors">Pengaturan</Link>
                <span v-else>Pengaturan</span>
                <span>/</span>
                <span class="text-slate-200 font-medium">{{ title }}</span>
            </div>
        </template>

        <div class="p-6">
            <div class="flex flex-col lg:flex-row gap-6">
                <!-- Sidebar kategori -->
                <div class="lg:w-56 flex-shrink-0 space-y-5">
                    <div v-for="group in visibleNavGroups" :key="group.label">
                        <div class="text-slate-500 text-[10px] font-semibold uppercase tracking-wider px-3 mb-1.5">
                            {{ group.label }}
                        </div>
                        <div class="space-y-0.5">
                            <Link v-for="item in group.items" :key="item.route" :href="route(item.route)"
                                :class="isActive(item.route)
                                    ? 'bg-violet-600/15 text-violet-300'
                                    : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800'"
                                class="block px-3 py-2 rounded-lg text-sm transition-colors">
                                {{ item.label }}
                            </Link>
                        </div>
                    </div>
                </div>

                <!-- Konten halaman -->
                <div class="flex-1 min-w-0 space-y-5">
                    <slot />
                </div>
            </div>
        </div>
    </BerandaLayout>
</template>
