<script setup>
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { useToasts } from '@/Composables/useToasts';

const page = usePage();
const user = computed(() => page.props.auth.user);
const { toasts } = useToasts();

const roleLabel = computed(() => {
    const roleMap = {
        superadmin:      { label: 'Super Admin',   color: 'bg-violet-500/20 text-violet-300' },
        manajer:         { label: 'Manajer',        color: 'bg-blue-500/20 text-blue-300' },
        sales:           { label: 'Sales',          color: 'bg-emerald-500/20 text-emerald-300' },
        staff_lapangan:  { label: 'Staff Lapangan', color: 'bg-amber-500/20 text-amber-300' },
        finance:         { label: 'Finance',        color: 'bg-teal-500/20 text-teal-300' },
        staff_kpr:       { label: 'Staff KPR',      color: 'bg-fuchsia-500/20 text-fuchsia-300' },
    };
    const role = user.value?.roles?.[0];
    return roleMap[role] ?? { label: role ?? 'Tanpa Role', color: 'bg-slate-700 text-slate-400' };
});

const logout = () => router.post(route('logout'));
</script>

<template>
    <div class="min-h-screen bg-slate-950 font-sans">
        <!-- Top bar — sengaja tanpa sidebar navigasi: Beranda cuma tempat
             pilih proyek & menu global, bukan bagian dari navigasi reguler. -->
        <header class="flex items-center justify-between h-16 px-6 border-b border-slate-800 bg-slate-900/50 backdrop-blur-sm gap-4">
            <Link :href="route('beranda')" class="flex items-center gap-3 flex-shrink-0">
                <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-violet-500 to-indigo-600 flex items-center justify-center shadow-lg shadow-violet-500/30">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4 text-white">
                        <path d="M11.47 3.84a.75.75 0 011.06 0l8.69 8.69a.75.75 0 101.06-1.06l-8.689-8.69a2.25 2.25 0 00-3.182 0l-8.69 8.69a.75.75 0 001.061 1.06l8.69-8.69z" />
                        <path d="M12 5.432l8.159 8.159c.03.03.06.058.091.086v6.198c0 1.035-.84 1.875-1.875 1.875H15a.75.75 0 01-.75-.75v-4.5a.75.75 0 00-.75-.75h-3a.75.75 0 00-.75.75V21a.75.75 0 01-.75.75H5.625a1.875 1.875 0 01-1.875-1.875v-6.198a2.29 2.29 0 00.091-.086L12 5.43z" />
                    </svg>
                </div>
                <div class="hidden md:block">
                    <div class="text-white font-semibold text-sm leading-none">ERP Property</div>
                    <div class="text-slate-400 text-xs mt-0.5">Management System</div>
                </div>
            </Link>

            <!-- Breadcrumb halaman (opsional) -->
            <div class="flex-1 min-w-0">
                <slot name="header" />
            </div>

            <div class="flex items-center gap-2 flex-shrink-0">
                <Link :href="route('profile.edit')" class="flex items-center gap-2.5 px-2 py-1.5 rounded-lg hover:bg-slate-800 transition-colors">
                    <div class="w-7 h-7 rounded-full bg-gradient-to-br from-violet-500 to-indigo-600 flex items-center justify-center text-white text-xs font-semibold flex-shrink-0">
                        {{ user.name?.slice(0, 2).toUpperCase() }}
                    </div>
                    <div class="hidden sm:block text-left">
                        <div class="text-slate-200 text-xs font-medium leading-none">{{ user.name }}</div>
                        <span :class="roleLabel.color" class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium mt-1">
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

        <main class="bg-slate-950">
            <slot />
        </main>

        <!-- Toast Notifikasi -->
        <Teleport to="body">
            <div class="fixed top-4 right-4 z-[100] flex flex-col gap-2 w-full max-w-sm">
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
                        <button @click="toasts = toasts.filter(t => t.id !== toast.id)" class="flex-shrink-0 opacity-60 hover:opacity-100">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"/></svg>
                        </button>
                    </div>
                </transition-group>
            </div>
        </Teleport>
    </div>
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
