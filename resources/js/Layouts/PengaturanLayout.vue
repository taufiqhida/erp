<script setup>
import BerandaLayout from '@/Layouts/BerandaLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    title: { type: String, required: true },
});

const navGroups = [
    {
        label: 'Identitas & Branding',
        items: [
            { label: 'Profil Developer', route: 'pengaturan.profil-developer' },
            { label: 'Template Surat',   route: 'pengaturan.surat-templates' },
        ],
    },
    {
        label: 'Preset Transaksi Penjualan',
        items: [
            { label: 'Template Pemberkasan', route: 'pengaturan.dokumen-templates' },
            { label: 'Biaya Tambahan',       route: 'pengaturan.biaya-tambahan' },
            { label: 'Promo',                route: 'pengaturan.promo' },
            { label: 'Skema DP',             route: 'pengaturan.skema-dp' },
            { label: 'Dana Jaminan & SBUM',  route: 'pengaturan.dajam-sbum' },
        ],
    },
    {
        label: 'Tim Penjualan',
        items: [
            { label: 'Sales / Agent', route: 'pengaturan.sales-agents' },
        ],
    },
];

const isActive = (routeName) => route().current(routeName) || route().current(`${routeName}.*`);
</script>

<template>
    <Head :title="`Pengaturan – ${title}`" />
    <BerandaLayout>
        <template #header>
            <div class="flex items-center gap-2 text-slate-400 text-sm">
                <Link :href="route('pengaturan.profil-developer')" class="hover:text-slate-200 transition-colors">Pengaturan</Link>
                <span>/</span>
                <span class="text-slate-200 font-medium">{{ title }}</span>
            </div>
        </template>

        <div class="p-6">
            <div class="flex flex-col lg:flex-row gap-6">
                <!-- Sidebar kategori -->
                <div class="lg:w-56 flex-shrink-0 space-y-5">
                    <div v-for="group in navGroups" :key="group.label">
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
