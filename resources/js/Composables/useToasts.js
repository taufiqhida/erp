import { ref, watch, onMounted, onUnmounted } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Toast notifikasi global — dipakai bareng oleh AuthenticatedLayout &
 * BerandaLayout supaya flash.success/error/warning dari backend (dan event
 * 'app:toast' utk error non-Inertia, mis. 403) tampil konsisten di kedua shell.
 */
export function useToasts() {
    const page = usePage();
    const toasts = ref([]);
    let toastId = 0;

    const pushToast = (type, message) => {
        if (!message) return;
        const id = ++toastId;
        toasts.value.push({ id, type, message });
        setTimeout(() => {
            toasts.value = toasts.value.filter(t => t.id !== id);
        }, type === 'success' ? 5000 : 8000);
    };

    watch(() => page.props.flash?.success, (msg) => msg && pushToast('success', msg));
    watch(() => page.props.flash?.error, (msg) => msg && pushToast('error', msg));
    watch(() => page.props.flash?.warning, (msg) => {
        if (!msg) return;
        const details = page.props.flash?.importErrors;
        const full = Array.isArray(details) && details.length
            ? `${msg}\n${details.slice(0, 5).join('\n')}${details.length > 5 ? `\n(+${details.length - 5} lainnya)` : ''}`
            : msg;
        pushToast('warning', full);
    });

    const onAppToast = (e) => pushToast(e.detail.type, e.detail.message);
    onMounted(() => window.addEventListener('app:toast', onAppToast));
    onUnmounted(() => window.removeEventListener('app:toast', onAppToast));

    return { toasts, pushToast };
}
