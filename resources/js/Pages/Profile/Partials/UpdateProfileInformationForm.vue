<script setup>
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

// Data akun hanya ditampilkan. Nama dan email tidak bisa diubah sendiri: email adalah nama login dan nama tampil
// di Audit Trail, jadi perubahannya dilakukan superadmin di Manajemen Role (tercatat).
const user = computed(() => usePage().props.auth.user);

const NAMA_ROLE = {
    superadmin: 'Superadmin', manager: 'Manager', spv: 'SPV', leader: 'Leader', admin_sales: 'Admin Sales',
    admin_pemberkasan: 'Admin Pemberkasan', admin_proyek: 'Admin Proyek', pelaksana_lapangan: 'Pelaksana Lapangan',
    admin_keuangan: 'Admin Keuangan',
};
const peran = computed(() => (user.value?.roles ?? []).map(r => NAMA_ROLE[r] ?? r).join(', ') || 'Tanpa role');
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-white">
                Informasi Akun
            </h2>

            <p class="mt-1 text-sm text-slate-400">
                Nama dan email dipakai untuk login dan tercatat di riwayat kerja, jadi hanya superadmin yang bisa mengubahnya.
                Perlu diganti? Hubungi superadmin.
            </p>
        </header>

        <dl class="mt-6 space-y-4 text-sm">
            <div>
                <dt class="text-slate-400">Nama</dt>
                <dd id="profil-nama" class="mt-1 text-white font-medium">{{ user.name }}</dd>
            </div>
            <div>
                <dt class="text-slate-400">Email (nama login)</dt>
                <dd id="profil-email" class="mt-1 text-white font-medium">{{ user.email }}</dd>
            </div>
            <div>
                <dt class="text-slate-400">Role</dt>
                <dd class="mt-1 text-white font-medium">{{ peran }}</dd>
            </div>
        </dl>
    </section>
</template>
