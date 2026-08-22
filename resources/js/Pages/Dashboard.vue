<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    stats: Object,
    recentActivities: Array,
    projectsSummary: Array,
    sp3kMonitoring: Array,
});

const sp3kBadge = {
    safe:     { label: 'Berlaku',  cls: 'bg-emerald-500/15 text-emerald-400' },
    warning:  { label: '≤30 hari', cls: 'bg-amber-500/15 text-amber-400' },
    critical: { label: '≤14 hari', cls: 'bg-orange-500/15 text-orange-400' },
    expired:  { label: 'Expired',  cls: 'bg-rose-500/15 text-rose-400' },
};

const page = usePage();
const flash = computed(() => page.props.flash ?? {});

const formatNumber = (num) => new Intl.NumberFormat('id-ID').format(num ?? 0);

const statCards = [
    {
        label: 'Total Proyek Aktif',
        value: props.stats?.total_projects ?? 0,
        icon: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z" /></svg>`,
        color: 'from-violet-500 to-indigo-600',
        shadow: 'shadow-violet-500/20',
    },
    {
        label: 'Total Kavling',
        value: props.stats?.total_kavlings ?? 0,
        icon: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15" /></svg>`,
        color: 'from-blue-500 to-cyan-600',
        shadow: 'shadow-blue-500/20',
    },
    {
        label: 'Kavling Tersedia',
        value: props.stats?.kavling_available ?? 0,
        icon: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>`,
        color: 'from-emerald-500 to-green-600',
        shadow: 'shadow-emerald-500/20',
    },
    {
        label: 'Kavling Terjual',
        value: props.stats?.kavling_sold ?? 0,
        icon: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z" /></svg>`,
        color: 'from-amber-500 to-orange-600',
        shadow: 'shadow-amber-500/20',
    },
    {
        label: 'Pending Pembatalan',
        value: props.stats?.pending_cancellations ?? 0,
        icon: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>`,
        color: 'from-rose-500 to-pink-600',
        shadow: 'shadow-rose-500/20',
        urgent: true,
    },
];
</script>

<template>
    <Head title="Dashboard" />
    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-slate-200 font-semibold text-sm">Dashboard</h1>
        </template>

        <div class="p-6 space-y-6">
            <!-- Flash Error (misal: akses ditolak, redirect ke sini) -->
            <div v-if="flash.error" class="flex items-center gap-3 px-4 py-3 bg-rose-500/10 border border-rose-500/20 rounded-xl text-rose-400 text-sm">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5 flex-shrink-0"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd"/></svg>
                {{ flash.error }}
            </div>

            <!-- Stats Grid -->
            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-4">
                <div
                    v-for="card in statCards"
                    :key="card.label"
                    class="relative bg-slate-900 border border-slate-800 rounded-xl p-4 overflow-hidden group hover:border-slate-700 transition-colors"
                    :class="{ 'ring-1 ring-rose-500/30': card.urgent && card.value > 0 }"
                >
                    <div class="flex items-start justify-between mb-3">
                        <div :class="['w-10 h-10 rounded-lg bg-gradient-to-br flex items-center justify-center text-white shadow-lg', card.color, card.shadow]">
                            <span v-html="card.icon" />
                        </div>
                        <Link
                            v-if="card.urgent && card.value > 0"
                            :href="route('cancellation-requests.index')"
                            class="text-xs text-rose-400 hover:text-rose-300 transition-colors font-medium"
                        >
                            Review →
                        </Link>
                    </div>
                    <div class="text-2xl font-bold text-white mb-0.5">{{ formatNumber(card.value) }}</div>
                    <div class="text-slate-500 text-xs font-medium">{{ card.label }}</div>

                    <!-- Subtle glow -->
                    <div :class="['absolute -bottom-4 -right-4 w-16 h-16 rounded-full bg-gradient-to-br opacity-10 blur-xl', card.color]" />
                </div>
            </div>

            <!-- Bottom Grid: Projects Summary + Recent Activity -->
            <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
                <!-- Projects Summary -->
                <div class="xl:col-span-2 bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                    <div class="flex items-center justify-between px-5 py-4 border-b border-slate-800">
                        <h2 class="text-slate-200 font-semibold text-sm">Proyek Aktif</h2>
                        <Link :href="route('beranda')" class="text-xs text-violet-400 hover:text-violet-300 transition-colors font-medium">
                            Lihat semua →
                        </Link>
                    </div>
                    <div class="divide-y divide-slate-800/70">
                        <div v-if="!projectsSummary?.length" class="px-5 py-8 text-center text-slate-500 text-sm">
                            Belum ada proyek aktif.
                        </div>
                        <div
                            v-for="project in projectsSummary"
                            :key="project.id"
                            class="px-5 py-4 hover:bg-slate-800/30 transition-colors group"
                        >
                            <div class="flex items-center justify-between mb-2">
                                <div>
                                    <Link
                                        :href="route('projects.show', project.id)"
                                        class="text-slate-200 text-sm font-medium hover:text-violet-300 transition-colors"
                                    >
                                        {{ project.nama }}
                                    </Link>
                                    <div class="text-slate-500 text-xs mt-0.5">{{ project.kode }} · {{ project.kota }}</div>
                                </div>
                                <div class="text-right">
                                    <div class="text-slate-200 text-sm font-semibold">{{ project.kavlings_sold }} / {{ project.kavlings_count }}</div>
                                    <div class="text-slate-500 text-xs">Terjual</div>
                                </div>
                            </div>
                            <!-- Progress Bar -->
                            <div class="h-1.5 bg-slate-800 rounded-full overflow-hidden">
                                <div
                                    class="h-full bg-gradient-to-r from-violet-500 to-indigo-500 rounded-full transition-all duration-700"
                                    :style="{ width: project.progress + '%' }"
                                />
                            </div>
                            <div class="text-slate-500 text-xs mt-1 text-right">{{ project.progress }}% terjual</div>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                    <div class="px-5 py-4 border-b border-slate-800">
                        <h2 class="text-slate-200 font-semibold text-sm">Aktivitas Terbaru</h2>
                    </div>
                    <div class="divide-y divide-slate-800/70 overflow-y-auto max-h-80">
                        <div v-if="!recentActivities?.length" class="px-5 py-8 text-center text-slate-500 text-sm">
                            Belum ada aktivitas.
                        </div>
                        <div
                            v-for="activity in recentActivities"
                            :key="activity.id"
                            class="px-5 py-3"
                        >
                            <div class="flex items-start gap-3">
                                <div class="w-7 h-7 rounded-full bg-gradient-to-br from-violet-500/30 to-indigo-600/30 border border-violet-500/20 flex items-center justify-center text-violet-400 text-xs font-semibold flex-shrink-0 mt-0.5">
                                    {{ activity.causer_name?.slice(0, 1) ?? 'S' }}
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-slate-300 text-xs leading-relaxed">{{ activity.description }}</p>
                                    <div class="flex items-center gap-2 mt-1">
                                        <span class="text-slate-500 text-xs">{{ activity.causer_name }}</span>
                                        <span class="text-slate-700 text-xs">·</span>
                                        <span class="text-slate-500 text-xs">{{ activity.created_at }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SP3K Expiry Monitoring -->
            <div v-if="sp3kMonitoring?.length" class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-800 flex items-center justify-between">
                    <h2 class="text-slate-200 font-semibold text-sm">🔔 Monitoring Masa Berlaku SP3K</h2>
                    <span class="text-slate-500 text-xs">{{ sp3kMonitoring.length }} transaksi</span>
                </div>
                <div class="divide-y divide-slate-800/70">
                    <div v-for="item in sp3kMonitoring" :key="item.id"
                        class="px-5 py-3 flex items-center justify-between hover:bg-slate-800/20 transition-colors">
                        <div>
                            <div class="text-slate-200 text-sm font-medium">{{ item.konsumen_nama }}</div>
                            <div class="text-slate-500 text-xs mt-0.5">{{ item.kavling }} · {{ item.project }}</div>
                        </div>
                        <div class="text-right">
                            <span :class="sp3kBadge[item.sp3k_expiry_status]?.cls" class="text-xs px-2 py-0.5 rounded-full font-medium">
                                {{ sp3kBadge[item.sp3k_expiry_status]?.label }}
                            </span>
                            <div class="text-slate-500 text-xs mt-1">{{ item.tanggal_expired_sp3k }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
