<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') · {{ config('app.name', 'Ventaship') }}</title>
    <link rel="icon" href="{{ asset('brand/favicon-64.png') }}" type="image/png">
    <link rel="apple-touch-icon" href="{{ asset('brand/apple-touch-icon.png') }}">
    @include('partials.theme-boot')
    <link rel="preload" href="{{ Vite::asset('resources/fonts/Archivo-latin.woff2') }}" as="font" type="font/woff2" crossorigin>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bl-app">
<main class="lg-stage">
    @yield('content')
</main>
</body>
</html>
