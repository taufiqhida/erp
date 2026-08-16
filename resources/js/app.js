import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Tangani response non-Inertia (403/419/500/dll) agar tidak gagal diam-diam.
// Tanpa ini, aksi yang ditolak backend (mis. authorize() gagal) tidak
// menampilkan apa pun ke user meski request-nya sebenarnya gagal.
router.on('invalid', (event) => {
    event.preventDefault();
    const status = event.detail.response?.status;
    const message = status === 403
        ? 'Anda tidak memiliki akses untuk melakukan aksi ini.'
        : status === 419
            ? 'Sesi Anda sudah berakhir, silakan muat ulang halaman.'
            : status === 422
                ? 'Data yang dikirim tidak valid.'
                : `Terjadi kesalahan (${status ?? 'tidak diketahui'}). Silakan coba lagi.`;
    window.dispatchEvent(new CustomEvent('app:toast', { detail: { type: 'error', message } }));
});

router.on('exception', (event) => {
    event.preventDefault();
    window.dispatchEvent(new CustomEvent('app:toast', {
        detail: { type: 'error', message: 'Terjadi kesalahan tak terduga. Silakan coba lagi.' },
    }));
});

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
