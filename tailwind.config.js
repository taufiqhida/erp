import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                // Teks sekunder (text-slate-500) dicerahkan sedikit supaya lolos kontras WCAG AA (>= 4,5:1)
                // di atas latar slate-900/800 (bawaan Tailwind hanya 3,75:1 / 3,07:1). Tidak ada bg/border-slate-500.
                slate: { 500: '#8696ad' },
            },
        },
    },

    plugins: [forms],
};
