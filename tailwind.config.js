import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

const skala = (nama) => Object.fromEntries(
    [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950].map((n) => [n, `rgb(var(--${nama}-${n}) / <alpha-value>)`]),
);

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
                // `slate` = permukaan/teks netral, `violet` = warna aksen. Keduanya dibaca dari variabel CSS
                // (resources/css/tema.css) sehingga bisa berganti tema terang/gelap dan aksen tanpa mengubah kelas.
                slate: skala('s'),
                violet: skala('a'),
            },
        },
    },

    plugins: [forms],
};
