<script setup>
import PengaturanLayout from '@/Layouts/PengaturanLayout.vue';
import { Link, router } from '@inertiajs/vue3';

const props = defineProps({
    agents: Array,
});

const toggleActive = (agent) => {
    router.patch(route('pengaturan.sales-agents.toggle', agent.id), {}, { preserveScroll: true });
};

const deleteAgent = (agent) => {
    if (!confirm(`Hapus sales/agent "${agent.nama}"?`)) return;
    router.delete(route('pengaturan.sales-agents.destroy', agent.id));
};
</script>

<template>
    <PengaturanLayout title="Sales / Agent">
        <div class="space-y-5">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-white font-bold text-xl">Master Sales / Agent</h1>
                    <p class="text-slate-400 text-sm mt-0.5">Data sales inhouse & agent freelance beserta skema komisi</p>
                </div>
                <Link :href="route('pengaturan.sales-agents.create')"
                    class="inline-flex items-center gap-2 px-4 py-2.5 bg-gradient-to-r from-violet-600 to-indigo-600 hover:from-violet-500 hover:to-indigo-500 text-white text-sm font-medium rounded-lg transition-all shadow-lg shadow-violet-500/20">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4"><path d="M10.75 4.75a.75.75 0 00-1.5 0v4.5h-4.5a.75.75 0 000 1.5h4.5v4.5a.75.75 0 001.5 0v-4.5h4.5a.75.75 0 000-1.5h-4.5v-4.5z"/></svg>
                    Tambah Sales/Agent
                </Link>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-800">
                                <th class="text-left px-5 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Nama</th>
                                <th class="text-left px-4 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Tipe</th>
                                <th class="text-left px-4 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Kontak</th>
                                <th class="text-left px-4 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Agency</th>
                                <th class="text-left px-4 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Komisi</th>
                                <th class="text-center px-4 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Status</th>
                                <th class="text-right px-5 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/70">
                            <tr v-if="!agents.length">
                                <td colspan="7" class="text-center py-12 text-slate-500">Belum ada data sales/agent.</td>
                            </tr>
                            <tr v-for="agent in agents" :key="agent.id" class="hover:bg-slate-800/30 transition-colors">
                                <td class="px-5 py-3.5">
                                    <div class="text-slate-200 font-medium">{{ agent.nama }}</div>
                                    <div v-if="agent.user_nama" class="text-slate-500 text-xs mt-0.5">Akun: {{ agent.user_nama }}</div>
                                </td>
                                <td class="px-4 py-3.5">
                                    <span :class="agent.tipe === 'freelance' ? 'bg-indigo-500/15 text-indigo-400' : 'bg-blue-500/15 text-blue-400'"
                                        class="px-2 py-0.5 rounded-full text-xs font-medium">
                                        {{ agent.tipe_label }}
                                    </span>
                                </td>
                                <td class="px-4 py-3.5 text-slate-400 text-xs">
                                    <div>{{ agent.no_hp ?? '-' }}</div>
                                    <div class="text-slate-500">{{ agent.email ?? '' }}</div>
                                </td>
                                <td class="px-4 py-3.5 text-slate-400 text-xs">{{ agent.agency_nama ?? '-' }}</td>
                                <td class="px-4 py-3.5 text-violet-300 text-sm font-medium">{{ agent.komisi_label }}</td>
                                <td class="px-4 py-3.5 text-center">
                                    <button @click="toggleActive(agent)"
                                        :class="agent.is_active ? 'bg-emerald-500/15 text-emerald-400' : 'bg-slate-700 text-slate-400'"
                                        class="px-2 py-1 rounded-lg text-xs font-medium transition-colors">
                                        {{ agent.is_active ? 'Aktif' : 'Nonaktif' }}
                                    </button>
                                </td>
                                <td class="px-5 py-3.5 text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <Link :href="route('pengaturan.sales-agents.edit', agent.id)"
                                            class="p-1.5 text-slate-500 hover:text-amber-400 hover:bg-amber-400/10 rounded-md transition-colors" title="Edit">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                                        </Link>
                                        <button @click="deleteAgent(agent)"
                                            class="p-1.5 text-slate-500 hover:text-rose-400 hover:bg-rose-400/10 rounded-md transition-colors" title="Hapus">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </PengaturanLayout>
</template>
