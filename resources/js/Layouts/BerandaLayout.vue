<script setup>
import BrandMark from '@/Components/BrandMark.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import UkuranTeks from '@/Components/UkuranTeks.vue';
import PratinjauTema from '@/Components/PratinjauTema.vue';
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { useToasts } from '@/Composables/useToasts';

const page = usePage();
const branding = computed(() => page.props.branding ?? {});
const user = computed(() => page.props.auth.user);
const { toasts } = useToasts();

const roleLabel = computed(() => {
    const roleMap = {
        superadmin:          { label: 'Superadmin',         color: 'bg-violet-500/20 text-violet-300' },
        manager:             { label: 'Manager',            color: 'bg-blue-500/20 text-blue-300' },
        spv:                 { label: 'SPV',                color: 'bg-sky-500/20 text-sky-300' },
        leader:              { label: 'Leader',              color: 'bg-indigo-500/20 text-indigo-300' },
        admin_sales:         { label: 'Admin Sales',         color: 'bg-emerald-500/20 text-emerald-300' },
        admin_pemberkasan:   { label: 'Admin Pemberkasan',   color: 'bg-fuchsia-500/20 text-fuchsia-300' },
        admin_proyek:        { label: 'Admin Proyek',        color: 'bg-amber-500/20 text-amber-300' },
        pelaksana_lapangan:  { label: 'Pelaksana Lapangan',  color: 'bg-orange-500/20 text-orange-300' },
        admin_keuangan:      { label: 'Admin Keuangan',      color: 'bg-teal-500/20 text-teal-300' },
    };
    const role = user.value?.roles?.[0];
    return roleMap[role] ?? { label: role ?? 'Tanpa Role', color: 'bg-slate-700 text-slate-400' };
});

const logout = () => router.post(route('logout'));
</script>

<template>
    <div class="min-h-screen bg-slate-950 font-sans">
        <a href="#konten-utama"
            class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[110] focus:px-4 focus:py-2 focus:rounded-lg focus:bg-violet-600 focus:text-white focus:text-sm">
            Lewati ke konten utama
        </a>
        <!-- Top bar — sengaja tanpa sidebar navigasi: Beranda cuma tempat
             pilih proyek & menu global, bukan bagian dari navigasi reguler. -->
        <header class="flex items-center justify-between h-16 px-6 border-b border-slate-800 bg-slate-900/50 backdrop-blur-sm gap-4">
            <Link :href="route('beranda')" class="flex items-center gap-3 flex-shrink-0">
                <BrandMark />
                <div class="hidden md:block min-w-0">
                    <div class="text-white font-semibold text-sm leading-none truncate">{{ branding.nama_developer ?? 'SSID' }}</div>
                    <div class="text-slate-400 text-xs mt-0.5 truncate">{{ branding.nama_sistem }}</div>
                </div>
            </Link>

            <!-- Breadcrumb halaman (opsional) -->
            <div class="flex-1 min-w-0">
                <slot name="header" />
            </div>

            <div class="flex items-center gap-2 flex-shrink-0">
                <UkuranTeks class="hidden sm:flex" />
                <Link :href="route('profile.edit')" class="flex items-center gap-2.5 px-2 py-1.5 rounded-lg hover:bg-slate-800 transition-colors">
                    <div class="w-7 h-7 rounded-full bg-gradient-to-br from-violet-500 to-indigo-600 flex items-center justify-center text-white text-xs font-semibold flex-shrink-0">
                        {{ user.name?.slice(0, 2).toUpperCase() }}
                    </div>
                    <div class="hidden sm:block text-left">
                        <div class="text-slate-200 text-xs font-medium leading-none">{{ user.name }}</div>
                        <span :class="roleLabel.color" class="inline-flex items-center px-1.5 py-0.5 rounded text-[0.625rem] font-medium mt-1">
                            {{ roleLabel.label }}
                        </span>
                    </div>
                </Link>
                <button id="logout-btn" @click="logout" title="Keluar"
                    class="p-2 text-slate-500 hover:text-rose-400 hover:bg-rose-500/10 rounded-lg transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" />
                    </svg>
                </button>
            </div>
        </header>

        <main id="konten-utama" tabindex="-1" class="bg-slate-950 focus:outline-none">
            <slot />
        </main>

        <!-- Toast Notifikasi -->
        <Teleport to="body">
            <div class="fixed top-4 right-4 z-[100] flex flex-col gap-2 w-full max-w-sm" role="status" aria-live="polite">
                <transition-group name="toast">
                    <div
                        v-for="toast in toasts"
                        :key="toast.id"
                        :class="{
                            'bg-emerald-500/15 border-emerald-500/30 text-emerald-400': toast.type === 'success',
                            'bg-rose-500/15 border-rose-500/30 text-rose-400': toast.type === 'error',
                            'bg-amber-500/15 border-amber-500/30 text-amber-400': toast.type === 'warning',
                        }"
                        class="flex items-start gap-2.5 px-4 py-3 rounded-xl border shadow-2xl backdrop-blur-sm text-sm"
                    >
                        <span class="flex-1 whitespace-pre-line">{{ toast.message }}</span>
                        <button type="button" aria-label="Tutup pemberitahuan" @click="toasts = toasts.filter(t => t.id !== toast.id)" class="flex-shrink-0 opacity-60 hover:opacity-100">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"/></svg>
                        </button>
                    </div>
                </transition-group>
            </div>
        </Teleport>
    </div>
    <ConfirmDialog />
    <PratinjauTema v-if="$page.props.pratinjauTema" />
</template>

<style scoped>
.toast-enter-active,
.toast-leave-active {
    transition: all 0.25s ease;
}
.toast-enter-from {
    opacity: 0;
    transform: translateX(1rem);
}
.toast-leave-to {
    opacity: 0;
    transform: translateX(1rem);
}
</style>
