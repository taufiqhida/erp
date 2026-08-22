<script setup>
import { ref, computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { useToasts } from '@/Composables/useToasts';

const page = usePage();
const user = computed(() => page.props.auth.user);
const currentProject = computed(() => page.props.currentProject);
const sidebarOpen = ref(false);
const showUserMenu = ref(false);

// Helper: cek apakah user punya role tertentu
const hasRole = (role) => user.value?.roles?.includes(role) ?? false;
const hasAnyRole = (...roles) => roles.some(r => hasRole(r));
const hasPermission = (perm) => user.value?.permissions?.includes(perm) ?? false;

const logout = () => {
    router.post(route('logout'));
};

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

// Proyek & Penjualan butuh 1 proyek konkret — kalau ada proyek aktif,
// langsung ke halamannya; kalau tidak ("Semua Proyek"), fallback ke
// Halaman Utama Pilih Proyek (beranda) sebagai prompt.
const allNavItems = computed(() => [
    {
        label: 'Dashboard',
        href: route('dashboard'),
        routeName: 'dashboard',
        roles: null,
        icon: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" /></svg>`,
    },
    {
        label: 'Kavling',
        href: currentProject.value ? route('projects.show', currentProject.value.id) : route('beranda'),
        routeName: 'projects.*',
        roles: null,
        icon: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z" /></svg>`,
    },
    {
        label: 'Penjualan',
        href: currentProject.value ? route('penjualan.project', currentProject.value.id) : route('beranda'),
        routeName: 'penjualan.*',
        roles: ['superadmin', 'manajer', 'sales'],
        icon: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z" /></svg>`,
    },
    {
        label: 'Konsumen',
        href: route('konsumens.index'),
        routeName: 'konsumens.*',
        roles: ['superadmin', 'manajer', 'sales', 'staff_kpr'],
        icon: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>`,
    },
    {
        label: 'Keuangan',
        href: route('keuangan.index'),
        routeName: 'keuangan.*',
        roles: ['superadmin', 'manajer', 'finance', 'sales'],
        icon: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0118 16.5h-2.25m-7.5 0h7.5m-7.5 0l-1 3m8.5-3l1 3m0 0l.5 1.5m-.5-1.5h-9.5m0 0l-.5 1.5M9 11.25v1.5M12 9v3.75m3-6v6" /></svg>`,
    },
    {
        label: 'Pembatalan',
        href: route('cancellation-requests.index'),
        routeName: 'cancellation-requests.*',
        roles: ['superadmin', 'manajer', 'sales', 'finance'],
        icon: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" /></svg>`,
    },
    // Manajemen Role & Pengaturan SENGAJA tidak ada di sini — keduanya
    // pengaturan global, cuma bisa diakses lewat Halaman Utama (beranda),
    // bukan bagian dari navigasi reguler per-proyek.
]);

// Filter nav berdasarkan role
const navItems = computed(() => {
    return allNavItems.value.filter(item => {
        if (!item.roles) return true; // semua bisa akses
        return item.roles.some(r => hasRole(r));
    });
});

const isActive = (routeName) => {
    try {
        return route().current(routeName);
    } catch {
        return false;
    }
};
</script>

<template>
    <div class="flex h-screen bg-slate-950 font-sans overflow-hidden">
        <!-- Sidebar Overlay (mobile) -->
        <div
            v-if="sidebarOpen"
            class="fixed inset-0 z-20 bg-black/60 backdrop-blur-sm lg:hidden"
            @click="sidebarOpen = false"
        />

        <!-- Sidebar -->
        <aside
            :class="[
                'fixed inset-y-0 left-0 z-30 w-64 flex flex-col bg-slate-900 border-r border-slate-800 transition-transform duration-300 lg:static lg:translate-x-0',
                sidebarOpen ? 'translate-x-0' : '-translate-x-full'
            ]"
        >
            <!-- Logo — klik utk kembali ke Halaman Utama -->
            <Link :href="route('beranda')" class="flex items-center gap-3 px-6 py-5 border-b border-slate-800 hover:bg-slate-800/50 transition-colors">
                <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-violet-500 to-indigo-600 flex items-center justify-center shadow-lg shadow-violet-500/30">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4 text-white">
                        <path d="M11.47 3.84a.75.75 0 011.06 0l8.69 8.69a.75.75 0 101.06-1.06l-8.689-8.69a2.25 2.25 0 00-3.182 0l-8.69 8.69a.75.75 0 001.061 1.06l8.69-8.69z" />
                        <path d="M12 5.432l8.159 8.159c.03.03.06.058.091.086v6.198c0 1.035-.84 1.875-1.875 1.875H15a.75.75 0 01-.75-.75v-4.5a.75.75 0 00-.75-.75h-3a.75.75 0 00-.75.75V21a.75.75 0 01-.75.75H5.625a1.875 1.875 0 01-1.875-1.875v-6.198a2.29 2.29 0 00.091-.086L12 5.43z" />
                    </svg>
                </div>
                <div>
                    <div class="text-white font-semibold text-sm leading-none">ERP Property</div>
                    <div class="text-slate-400 text-xs mt-0.5">Management System</div>
                </div>
            </Link>

            <!-- Nav -->
            <nav class="flex-1 px-3 py-4 space-y-0.5 overflow-y-auto">
                <template v-for="item in navItems" :key="item.label">
                    <Link
                        :href="item.href"
                        :class="[
                            'flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all duration-150 group',
                            isActive(item.routeName)
                                ? 'bg-violet-600/20 text-violet-300 shadow-sm'
                                : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800'
                        ]"
                    >
                        <span
                            :class="[
                                'flex-shrink-0 transition-colors',
                                isActive(item.routeName) ? 'text-violet-400' : 'text-slate-500 group-hover:text-slate-300'
                            ]"
                            v-html="item.icon"
                        />
                        {{ item.label }}
                        <span
                            v-if="isActive(item.routeName)"
                            class="ml-auto w-1.5 h-1.5 rounded-full bg-violet-400"
                        />
                    </Link>
                </template>
            </nav>

            <!-- User Info Bottom -->
            <div class="px-3 py-4 border-t border-slate-800">
                <!-- User Menu Popup (renders above) -->
                <div v-if="showUserMenu" class="mb-2 bg-slate-800 border border-slate-700 rounded-xl overflow-hidden shadow-2xl">
                    <Link
                        :href="route('profile.edit')"
                        class="flex items-center gap-2.5 px-4 py-3 text-slate-300 hover:bg-slate-700 hover:text-white text-sm transition-colors"
                        @click="showUserMenu = false"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                        </svg>
                        Profil Saya
                    </Link>
                    <button
                        id="logout-btn"
                        @click="logout"
                        class="flex items-center gap-2.5 w-full px-4 py-3 text-rose-400 hover:bg-rose-500/10 hover:text-rose-300 text-sm transition-colors border-t border-slate-700"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" />
                        </svg>
                        Keluar
                    </button>
                </div>
                <!-- User Profile Button -->
                <button
                    id="user-menu-btn"
                    @click="showUserMenu = !showUserMenu"
                    class="flex items-center gap-3 w-full px-3 py-2.5 rounded-lg hover:bg-slate-800 transition-colors text-left group"
                >
                    <div class="w-8 h-8 rounded-full bg-gradient-to-br from-violet-500 to-indigo-600 flex items-center justify-center text-white text-xs font-semibold flex-shrink-0">
                        {{ user.name?.slice(0, 2).toUpperCase() }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-slate-200 text-sm font-medium truncate">{{ user.name }}</div>
                        <span :class="roleLabel.color" class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium mt-0.5">
                            {{ roleLabel.label }}
                        </span>
                    </div>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" :class="['w-4 h-4 text-slate-500 transition-transform', showUserMenu ? 'rotate-180' : '']">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5" />
                    </svg>
                </button>
            </div>
        </aside>

        <!-- Main Content -->
        <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
            <!-- Top Bar -->
            <header class="flex items-center gap-4 h-14 px-6 border-b border-slate-800 bg-slate-900/50 backdrop-blur-sm flex-shrink-0">
                <!-- Mobile hamburger -->
                <button
                    @click="sidebarOpen = !sidebarOpen"
                    class="lg:hidden text-slate-400 hover:text-slate-200 transition-colors"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                    </svg>
                </button>

                <!-- Page header slot -->
                <div class="flex-1">
                    <slot name="header" />
                </div>

                <!-- Proyek aktif — klik utk ganti proyek lewat Halaman Utama -->
                <Link :href="route('beranda')"
                    class="flex items-center gap-1.5 px-3 py-1.5 bg-slate-800 hover:bg-slate-700 border border-slate-700 rounded-lg text-sm transition-colors flex-shrink-0">
                    <span>🏢</span>
                    <span :class="currentProject ? 'text-slate-200 font-medium' : 'text-slate-500'">
                        {{ currentProject ? currentProject.nama : 'Pilih Proyek' }}
                    </span>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5 text-slate-500">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                    </svg>
                </Link>
            </header>

            <!-- Page Content -->
            <main class="flex-1 overflow-y-auto bg-slate-950">
                <slot />
            </main>
        </div>

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
                        <svg v-if="toast.type === 'success'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5 flex-shrink-0 mt-0.5"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd"/></svg>
                        <svg v-else-if="toast.type === 'warning'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5 flex-shrink-0 mt-0.5"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 6a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 6zm0 8a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>
                        <svg v-else xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5 flex-shrink-0 mt-0.5"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a.75.75 0 000 1.5h.253a.25.25 0 01.244.304l-.459 2.066A1.75 1.75 0 0010.747 15H11a.75.75 0 000-1.5h-.253a.25.25 0 01-.244-.304l.459-2.066A1.75 1.75 0 009.253 9H9z" clip-rule="evenodd"/></svg>
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
