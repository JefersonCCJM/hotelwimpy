{{-- SEO Meta Tags Partial --}}
{{-- Usage: @include('partials.seo', ['title' => 'Page Title', 'description' => 'Page description']) --}}

@php
    $appName = config('app.name', 'Hotel Wimpy');
    $pageTitle = isset($title) ? $title . ' - ' . $appName : $appName . ' - Sistema de Gestión Hotelera';
    $pageDescription = $description ?? 'Sistema integral de gestión hotelera para Hotel Wimpy. Administra reservaciones, habitaciones, inventario y facturación electrónica de manera eficiente.';
    $pageKeywords = $keywords ?? 'hotel, gestión hotelera, reservaciones, sistema hotelero, Hotel Wimpy, administración hotelera, facturación electrónica';
    $pageUrl = url()->current();
    $pageImage = $image ?? asset('assets/img/backgrounds/login-bg.jpeg');
@endphp

{{-- Primary Meta Tags --}}
<meta name="title" content="{{ $pageTitle }}">
<meta name="description" content="{{ $pageDescription }}">
<meta name="keywords" content="{{ $pageKeywords }}">
<meta name="author" content="Hotel Wimpy">
<meta name="robots" content="index, follow">
<meta name="language" content="Spanish">

{{-- Open Graph / Facebook --}}
<meta property="og:type" content="website">
<meta property="og:url" content="{{ $pageUrl }}">
<meta property="og:title" content="{{ $pageTitle }}">
<meta property="og:description" content="{{ $pageDescription }}">
<meta property="og:image" content="{{ $pageImage }}">
<meta property="og:site_name" content="{{ $appName }}">
<meta property="og:locale" content="es_ES">

{{-- Twitter --}}
<meta property="twitter:card" content="summary_large_image">
<meta property="twitter:url" content="{{ $pageUrl }}">
<meta property="twitter:title" content="{{ $pageTitle }}">
<meta property="twitter:description" content="{{ $pageDescription }}">
<meta property="twitter:image" content="{{ $pageImage }}">

{{-- Canonical URL --}}
<link rel="canonical" href="{{ $pageUrl }}">
