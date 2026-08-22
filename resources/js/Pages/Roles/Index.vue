<script setup>
import BerandaLayout from '@/Layouts/BerandaLayout.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    users:    Object,
    roles:    Array,
    projects: Array,
});

const page = usePage();
const flash = computed(() => page.props.flash ?? {});

// ── Edit Role Modal ──────────────────────────────────────────────────────────
const editingUser = ref(null);
const form = useForm({ roles: [] });

const openEdit = (user) => {
    editingUser.value = user;
    form.roles = [...user.roles];
};

const toggleRole = (roleName) => {
    const idx = form.roles.indexOf(roleName);
    if (idx > -1) {
        form.roles.splice(idx, 1);
    } else {
        form.roles.push(roleName);
    }
};

const submit = () => {
    form.patch(route('roles.assign', editingUser.value.id), {
        onSuccess: () => { editingUser.value = null; }
    });
};

// ── Assign Project Modal ─────────────────────────────────────────────────────
const assigningUser    = ref(null);
const projectForm      = useForm({ user_ids: [] });

const openAssignProject = (project) => {
    // re-purpose: we open the modal per-project to assign multiple users
    // OR per-user to assign multiple projects
    // Using per-user approach:
    assigningUser.value = null; // reset
};

// Per-project assignment modal
const selectedProject  = ref(null);
const assignedUserIds  = ref([]);
const showProjectModal = ref(false);

const openProjectAssign = (project) => {
    selectedProject.value = project;
    // Collect users currently assigned to this project
    assignedUserIds.value = props.users.data
        .filter(u => u.projects?.some(p => p.id === project.id))
        .map(u => u.id);
    showProjectModal.value = true;
};

const saveProjectAssign = () => {
    const form2 = useForm({ user_ids: assignedUserIds.value });
    form2.post(route('projects.assign-users', selectedProject.value.id), {
        onSuccess: () => { showProjectModal.value = false; }
    });
};

const toggleUserProject = (userId) => {
    const idx = assignedUserIds.value.indexOf(userId);
    if (idx > -1) assignedUserIds.value.splice(idx, 1);
    else assignedUserIds.value.push(userId);
};

// ── Add User Modal ───────────────────────────────────────────────────────────
const showAddUser = ref(false);
const addForm = useForm({
    name:                  '',
    email:                 '',
    password:              '',
    password_confirmation: '',
    roles:                 [],
});

const toggleAddRole = (roleName) => {
    const idx = addForm.roles.indexOf(roleName);
    if (idx > -1) {
        addForm.roles.splice(idx, 1);
    } else {
        addForm.roles.push(roleName);
    }
};

const submitAddUser = () => {
    addForm.post(route('users.store'), {
        onSuccess: () => {
            showAddUser.value = false;
            addForm.reset();
        }
    });
};

// ── Style helpers ────────────────────────────────────────────────────────────
const roleColors = {
    superadmin:      'bg-violet-500/20 text-violet-300 ring-1 ring-violet-500/30',
    manajer:         'bg-blue-500/20 text-blue-300 ring-1 ring-blue-500/30',
    sales:           'bg-emerald-500/20 text-emerald-300 ring-1 ring-emerald-500/30',
    staff_lapangan:  'bg-amber-500/20 text-amber-300 ring-1 ring-amber-500/30',
    finance:         'bg-teal-500/20 text-teal-300 ring-1 ring-teal-500/30',
    staff_kpr:       'bg-fuchsia-500/20 text-fuchsia-300 ring-1 ring-fuchsia-500/30',
};

const getRoleColor = (name) => roleColors[name] ?? 'bg-slate-700 text-slate-300 ring-1 ring-slate-600';
</script>

<template>
    <Head title="Manajemen Role" />
    <BerandaLayout>
        <template #header>
            <div class="flex items-center gap-2 text-slate-400 text-sm">
                <span class="text-slate-200 font-medium">Manajemen Role</span>
            </div>
        </template>

        <div class="p-6 space-y-5">
            <!-- Flash Message -->
            <div v-if="flash.success" class="flex items-center gap-3 px-4 py-3 bg-emerald-500/10 border border-emerald-500/20 rounded-xl text-emerald-400 text-sm">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5 flex-shrink-0"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd"/></svg>
                {{ flash.success }}
            </div>

            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-white font-bold text-xl">Manajemen Role Pengguna</h1>
                    <p class="text-slate-400 text-sm mt-0.5">Assign role ke setiap pengguna sistem</p>
                </div>
                <button
                    @click="showAddUser = true"
                    class="inline-flex items-center gap-2 px-3 py-2 bg-violet-600/20 hover:bg-violet-600/30 text-violet-300 text-xs font-medium rounded-lg transition-colors border border-violet-500/20"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3.5 h-3.5"><path d="M10.75 4.75a.75.75 0 00-1.5 0v4.5h-4.5a.75.75 0 000 1.5h4.5v4.5a.75.75 0 001.5 0v-4.5h4.5a.75.75 0 000-1.5h-4.5v-4.5z"/></svg>
                    Tambah User Baru
                </button>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-800">
                                <th class="text-left px-5 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Pengguna</th>
                                <th class="text-left px-4 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Role Aktif</th>
                                <th class="text-left px-4 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Proyek Ditugaskan</th>
                                <th class="text-right px-5 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/70">
                            <tr v-if="!users.data.length">
                                <td colspan="3" class="text-center py-12 text-slate-500">Tidak ada pengguna.</td>
                            </tr>
                            <tr
                                v-for="user in users.data"
                                :key="user.id"
                                class="hover:bg-slate-800/20 transition-colors"
                            >
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 rounded-full bg-gradient-to-br from-violet-500 to-indigo-600 flex items-center justify-center text-white text-xs font-semibold flex-shrink-0">
                                            {{ user.initials }}
                                        </div>
                                        <div>
                                            <div class="text-slate-200 font-medium text-sm">{{ user.name }}</div>
                                            <div class="text-slate-500 text-xs">{{ user.email }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="flex flex-wrap gap-1.5">
                                        <span
                                            v-for="role in user.roles"
                                            :key="role"
                                            :class="getRoleColor(role)"
                                            class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium"
                                        >
                                            {{ role }}
                                        </span>
                                        <span v-if="!user.roles.length" class="text-slate-600 text-xs">Tanpa role</span>
                                    </div>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="flex flex-wrap gap-1">
                                        <span v-for="p in user.projects" :key="p.id"
                                            class="px-1.5 py-0.5 bg-slate-800 text-slate-400 text-xs rounded font-mono">
                                            {{ p.kode }}
                                        </span>
                                        <span v-if="!user.projects?.length" class="text-slate-600 text-xs">–</span>
                                    </div>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex justify-end gap-1.5">
                                        <button
                                            @click="openEdit(user)"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium rounded-lg transition-colors"
                                        >
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" />
                                            </svg>
                                            Edit Role
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ── Assign Users ke Proyek ─────────────────────────── -->
        <div class="p-6 pt-0 space-y-4">
            <div>
                <h2 class="text-white font-semibold text-base">Penugasan Proyek</h2>
                <p class="text-slate-400 text-sm mt-0.5">Tentukan siapa yang boleh mengakses setiap proyek</p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <div v-for="project in projects" :key="project.id"
                    class="bg-slate-900 border border-slate-800 rounded-xl p-4 hover:border-slate-700 transition-colors">
                    <div class="flex items-center justify-between mb-3">
                        <div>
                            <div class="text-slate-200 font-medium text-sm">{{ project.nama }}</div>
                            <div class="text-slate-500 text-xs mt-0.5 font-mono">{{ project.kode }}</div>
                        </div>
                        <button @click="openProjectAssign(project)"
                            class="px-2.5 py-1 bg-violet-600/15 hover:bg-violet-600/25 text-violet-400 text-xs rounded-lg border border-violet-500/20 transition-colors">
                            Atur Users
                        </button>
                    </div>
                    <div class="flex flex-wrap gap-1">
                        <span v-for="user in users.data.filter(u => u.projects?.some(p => p.id === project.id))"
                            :key="user.id"
                            class="px-2 py-0.5 bg-slate-800 text-slate-300 text-xs rounded-full">
                            {{ user.name }}
                        </span>
                        <span v-if="!users.data.some(u => u.projects?.some(p => p.id === project.id))"
                            class="text-slate-600 text-xs">Belum ada user</span>
                    </div>
                </div>
            </div>
        </div>


        <Teleport to="body">
            <div v-if="editingUser" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" @click="editingUser = null" />
                <div class="relative bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-sm shadow-2xl">
                    <div class="flex items-center justify-between p-5 border-b border-slate-800">
                        <h3 class="text-white font-semibold">Assign Role: {{ editingUser.name }}</h3>
                        <button @click="editingUser = null" class="text-slate-500 hover:text-slate-300">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" /></svg>
                        </button>
                    </div>
                    <div class="p-5 space-y-3">
                        <p class="text-slate-400 text-xs">Pilih role yang akan ditetapkan (bisa lebih dari satu):</p>
                        <div class="space-y-2">
                            <label
                                v-for="role in roles"
                                :key="role.id"
                                class="flex items-center gap-3 p-3 rounded-lg border cursor-pointer transition-colors"
                                :class="form.roles.includes(role.name)
                                    ? 'border-violet-500/40 bg-violet-500/10'
                                    : 'border-slate-700 bg-slate-800/50 hover:border-slate-600'"
                            >
                                <input
                                    type="checkbox"
                                    :value="role.name"
                                    :checked="form.roles.includes(role.name)"
                                    @change="toggleRole(role.name)"
                                    class="w-4 h-4 rounded accent-violet-500"
                                />
                                <span :class="getRoleColor(role.name)" class="px-2 py-0.5 rounded-full text-xs font-medium">
                                    {{ role.name }}
                                </span>
                            </label>
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <button type="button" @click="editingUser = null" class="px-4 py-2 text-slate-400 text-sm">Batal</button>
                            <button @click="submit" :disabled="form.processing" class="px-4 py-2 bg-violet-600 hover:bg-violet-500 text-white text-sm font-medium rounded-lg transition-colors">
                                {{ form.processing ? 'Menyimpan...' : 'Simpan Role' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- ── MODAL: Tambah User Baru ───────────────────────────────────────── -->
        <Teleport to="body">
            <div v-if="showAddUser" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" @click="showAddUser = false" />
                <div class="relative bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-md shadow-2xl">
                    <div class="flex items-center justify-between p-5 border-b border-slate-800">
                        <div>
                            <h3 class="text-white font-semibold">Tambah User Baru</h3>
                            <p class="text-slate-400 text-xs mt-0.5">Akun langsung aktif tanpa verifikasi email</p>
                        </div>
                        <button @click="showAddUser = false" class="text-slate-500 hover:text-slate-300">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" /></svg>
                        </button>
                    </div>
                    <form @submit.prevent="submitAddUser" class="p-5 space-y-4">
                        <!-- Nama -->
                        <div>
                            <label class="block text-slate-400 text-xs font-medium mb-1.5">Nama Lengkap <span class="text-rose-400">*</span></label>
                            <input
                                v-model="addForm.name"
                                type="text"
                                placeholder="Contoh: Budi Santoso"
                                class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"
                                :class="{ 'border-rose-500': addForm.errors.name }"
                            />
                            <p v-if="addForm.errors.name" class="text-rose-400 text-xs mt-1">{{ addForm.errors.name }}</p>
                        </div>

                        <!-- Email -->
                        <div>
                            <label class="block text-slate-400 text-xs font-medium mb-1.5">Email <span class="text-rose-400">*</span></label>
                            <input
                                v-model="addForm.email"
                                type="email"
                                placeholder="budi@erp.local"
                                class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"
                                :class="{ 'border-rose-500': addForm.errors.email }"
                            />
                            <p v-if="addForm.errors.email" class="text-rose-400 text-xs mt-1">{{ addForm.errors.email }}</p>
                        </div>

                        <!-- Password -->
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-slate-400 text-xs font-medium mb-1.5">Password <span class="text-rose-400">*</span></label>
                                <input
                                    v-model="addForm.password"
                                    type="password"
                                    placeholder="Min. 8 karakter"
                                    class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"
                                    :class="{ 'border-rose-500': addForm.errors.password }"
                                />
                                <p v-if="addForm.errors.password" class="text-rose-400 text-xs mt-1">{{ addForm.errors.password }}</p>
                            </div>
                            <div>
                                <label class="block text-slate-400 text-xs font-medium mb-1.5">Konfirmasi Password</label>
                                <input
                                    v-model="addForm.password_confirmation"
                                    type="password"
                                    placeholder="Ulangi password"
                                    class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"
                                />
                            </div>
                        </div>

                        <!-- Role -->
                        <div>
                            <label class="block text-slate-400 text-xs font-medium mb-2">Role (opsional)</label>
                            <div class="grid grid-cols-2 gap-2">
                                <label
                                    v-for="role in roles"
                                    :key="role.id"
                                    class="flex items-center gap-2 p-2.5 rounded-lg border cursor-pointer transition-colors"
                                    :class="addForm.roles.includes(role.name)
                                        ? 'border-violet-500/40 bg-violet-500/10'
                                        : 'border-slate-700 bg-slate-800/50 hover:border-slate-600'"
                                >
                                    <input
                                        type="checkbox"
                                        :value="role.name"
                                        :checked="addForm.roles.includes(role.name)"
                                        @change="toggleAddRole(role.name)"
                                        class="w-3.5 h-3.5 rounded accent-violet-500"
                                    />
                                    <span :class="getRoleColor(role.name)" class="px-1.5 py-0.5 rounded-full text-xs font-medium">
                                        {{ role.name }}
                                    </span>
                                </label>
                            </div>
                        </div>

                        <div class="flex justify-end gap-3 pt-1">
                            <button type="button" @click="showAddUser = false" class="px-4 py-2.5 text-slate-400 text-sm">Batal</button>
                            <button
                                type="submit"
                                :disabled="addForm.processing"
                                class="px-5 py-2.5 bg-gradient-to-r from-violet-600 to-indigo-600 hover:from-violet-500 hover:to-indigo-500 disabled:opacity-50 text-white text-sm font-semibold rounded-xl transition-all shadow-lg shadow-violet-500/20"
                            >
                                {{ addForm.processing ? 'Membuat...' : 'Buat Akun' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <!-- ── MODAL: Assign Users ke Proyek ───────────────────── -->
        <Teleport to="body">
            <div v-if="showProjectModal && selectedProject"
                class="fixed inset-0 z-50 flex items-center justify-center p-4"
                @click.self="showProjectModal = false">
                <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" @click="showProjectModal = false" />
                <div class="relative bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-md shadow-2xl">
                    <div class="flex items-center justify-between px-6 py-4 border-b border-slate-800">
                        <div>
                            <h3 class="text-white font-semibold">Assign User ke Proyek</h3>
                            <p class="text-slate-400 text-xs mt-0.5">{{ selectedProject.nama }} ({{ selectedProject.kode }})</p>
                        </div>
                        <button @click="showProjectModal = false" class="text-slate-500 hover:text-slate-300">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>
                    <div class="px-6 py-4 space-y-2 max-h-72 overflow-y-auto">
                        <label v-for="user in users.data" :key="user.id"
                            class="flex items-center gap-3 p-2.5 rounded-lg hover:bg-slate-800 cursor-pointer transition-colors">
                            <input type="checkbox"
                                :checked="assignedUserIds.includes(user.id)"
                                @change="toggleUserProject(user.id)"
                                class="accent-violet-500 w-4 h-4" />
                            <div class="w-7 h-7 rounded-full bg-gradient-to-br from-violet-500 to-indigo-600 flex items-center justify-center text-white text-xs font-bold flex-shrink-0">
                                {{ user.initials }}
                            </div>
                            <div>
                                <div class="text-slate-200 text-sm font-medium">{{ user.name }}</div>
                                <div class="text-slate-500 text-xs">{{ user.roles.join(', ') || 'No role' }}</div>
                            </div>
                        </label>
                    </div>
                    <div class="px-6 py-4 border-t border-slate-800 flex gap-3">
                        <button @click="showProjectModal = false" class="flex-1 py-2.5 text-slate-400 hover:text-slate-200 text-sm border border-slate-700 rounded-lg transition-colors">
                            Batal
                        </button>
                        <button @click="saveProjectAssign"
                            class="flex-1 py-2.5 bg-gradient-to-r from-violet-600 to-indigo-600 hover:from-violet-500 hover:to-indigo-500 text-white text-sm font-medium rounded-lg transition-all shadow-lg shadow-violet-500/20">
                            Simpan Assignment
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </BerandaLayout>
</template>
