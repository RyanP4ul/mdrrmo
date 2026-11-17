<html>
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <script>
            (function () {
                try {
                    var theme = localStorage.getItem('theme');
                    var isDark = theme === 'dark' || ((!theme || theme === 'system') && window.matchMedia('(prefers-color-scheme: dark)').matches);
                    if (isDark) document.documentElement.classList.add('dark');
                } catch (e) {}
            })();
        </script>

        @viteReactRefresh
        
        @vite([
            'resources/css/app.css',
            'resources/js/app.tsx',
        ])

        <x-inertia::head />
        @routes
    </head>

    <body>
        <x-inertia::app />
    </body>
</html>
