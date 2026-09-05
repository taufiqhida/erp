<script setup>
import PengaturanLayout from '@/Layouts/PengaturanLayout.vue';
import { router } from '@inertiajs/vue3';

defineProps({
    statusJual: Array,
    statusPenjualan: Array,
});

const updateColor = (item, warna) => {
    router.patch(route('pengaturan.status-colors.update', item.id), { warna }, { preserveScroll: true });
};
</script>

<template>
    <PengaturanLayout title="Warna Status">
        <div>
            <h1 class="text-white font-bold text-xl">Warna Status</h1>
            <p class="text-slate-400 text-sm mt-0.5">
                Atur warna badge & marker siteplan untuk Status Jual dan Pipeline KPR. Daftar status & namanya tetap
                mengikuti alur sistem — di sini cuma warnanya yang bisa disesuaikan (mis. supaya selaras dengan
                SIKUMBANG).
            </p>
        </div>

        <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-800">
                <h3 class="text-white font-semibold text-sm">Status Jual</h3>
            </div>
            <div class="divide-y divide-slate-800/60">
                <div v-for="s in statusJual" :key="s.id" class="flex items-center justify-between px-5 py-3">
                    <div class="flex items-center gap-3">
                        <span class="w-4 h-4 rounded-full inline-block" :style="`background:${s.warna}`"></span>
                        <span class="text-slate-200 text-sm">{{ s.label }}</span>
                    </div>
                    <input type="color" :value="s.warna" @change="updateColor(s, $event.target.value)"
                        class="w-9 h-9 rounded cursor-pointer bg-transparent border border-slate-700" />
                </div>
            </div>
        </div>

        <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-800">
                <h3 class="text-white font-semibold text-sm">Pipeline KPR</h3>
            </div>
            <div class="divide-y divide-slate-800/60">
                <div v-for="s in statusPenjualan" :key="s.id" class="flex items-center justify-between px-5 py-3">
                    <div class="flex items-center gap-3">
                        <span class="w-4 h-4 rounded-full inline-block" :style="`background:${s.warna}`"></span>
                        <span class="text-slate-200 text-sm">{{ s.label }}</span>
                    </div>
                    <input type="color" :value="s.warna" @change="updateColor(s, $event.target.value)"
                        class="w-9 h-9 rounded cursor-pointer bg-transparent border border-slate-700" />
                </div>
            </div>
        </div>
    </PengaturanLayout>
</template>
