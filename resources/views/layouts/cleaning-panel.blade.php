<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Panel de Aseo - Hotel Wimpy</title>
    <link rel="icon" type="image/png" href="{{ asset('assets/img/backgrounds/logo-Photoroom.png') }}">
    
    <!-- TailwindCSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Alpine.js is included by Livewire, no need to load it separately -->
    <style>
        [x-cloak] { display: none !important; }
        
        .room-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .room-card:hover {
            transform: translateY(-2px);
        }
    </style>
    
    @livewireStyles
</head>
<body>
    {{ $slot }}
    
    @livewireScripts
    
    <script>
        // Debug Livewire initialization
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM loaded');
            if (typeof Livewire !== 'undefined') {
                console.log('Livewire is loaded');
                
                // Listen for ALL Livewire hooks
                Livewire.hook('morph.updated', ({ component, el }) => {
                    console.log('Livewire component updated:', component?.name);
                });
                
                Livewire.hook('message.processed', (message, component) => {
                    console.log('Livewire message processed:', message.fingerprint?.method);
                });
                
                Livewire.hook('message.failed', (message, component) => {
                    console.error('Livewire message failed:', message);
                });
                
                Livewire.hook('request', ({ uri, options, payload, respond, preventDefault }) => {
                    console.log('Livewire request:', {
                        uri: uri,
                        method: payload?.fingerprint?.method,
                        params: payload?.serverMemo?.data
                    });
                });
                
                Livewire.hook('commit', ({ component, commit, respond, succeed, fail }) => {
                    console.log('Livewire commit:', {
                        component: component?.name,
                        method: commit?.method,
                        params: commit?.params
                    });
                });
                
                // Listen for wire:click events
                document.addEventListener('click', function(e) {
                    if (e.target.closest('[wire\\:click]')) {
                        const wireClick = e.target.closest('[wire\\:click]').getAttribute('wire:click');
                        console.log('wire:click detected:', wireClick);
                    }
                }, true);
            } else {
                console.error('Livewire is NOT loaded!');
            }
        });
    </script>
</body>
</html>

