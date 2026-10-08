<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-ukuran="{{ auth()->user()?->ukuranFont() ?? 'normal' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ $brand['nama_singkat'] ?? 'SSID' }}</title>
        <link rel="icon" href="{{ $brand['favicon_url'] ?? '/favicon.svg' }}">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        @if (app()->environment('local'))
            {{-- Pratinjau tema (sementara): terapkan pilihan tersimpan sebelum halaman tampil, supaya tidak berkedip. --}}
            <script>try{var d=document.documentElement,s=JSON.parse(localStorage.getItem('ssid-tema-pratinjau')||'{}');['tema','aksen','latar'].forEach(function(k){if(s[k])d.dataset[k]=s[k]})}catch(e){}</script>
        @endif

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
