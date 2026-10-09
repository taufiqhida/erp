<!DOCTYPE html>
@php
    // Pilihan tema pengguna: gelap (bawaan), terang, atau sistem. "sistem" dirender gelap dulu lalu
    // diputuskan skrip kecil di bawah sebelum halaman tampil, sehingga tidak berkedip.
    $pilihanTema = auth()->user()?->temaPilihan() ?? 'gelap';
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      data-ukuran="{{ auth()->user()?->ukuranFont() ?? 'normal' }}"
      data-tema="{{ $pilihanTema === 'terang' ? 'terang' : 'gelap' }}"
      data-pilihan-tema="{{ $pilihanTema }}"
      @auth data-masuk="1" @endauth>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ $brand['nama_singkat'] ?? 'SSID' }}</title>
        <link rel="icon" href="{{ $brand['favicon_url'] ?? '/favicon.svg' }}">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        {{-- Tema sebelum halaman tampil: pengguna login = pilihan akun (dari server); tamu (halaman Masuk) = pilihan terakhir
             di perangkat ini; "sistem" = ikuti pengaturan terang/gelap perangkat. --}}
        <script>try{var d=document.documentElement;if(!d.dataset.masuk){var p=localStorage.getItem('ssid-tema-perangkat');if(p==='gelap'||p==='terang'||p==='sistem'){d.dataset.pilihanTema=p;d.dataset.tema=p==='terang'?'terang':'gelap'}}if(d.dataset.pilihanTema==='sistem'){d.dataset.tema=matchMedia('(prefers-color-scheme: light)').matches?'terang':'gelap'}}catch(e){}</script>

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
