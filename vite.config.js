
import { defineConfig } from "vite";
import laravel from "laravel-vite-plugin";
import { bunny } from "laravel-vite-plugin/fonts";
import tailwindcss from "@tailwindcss/vite";
import react from "@vitejs/plugin-react";
import inertia from "@inertiajs/vite";

export default defineConfig({
    plugins: [
        laravel({
            input: [
                "resources/css/app.css",
                "resources/js/app.tsx",
            ],
            refresh: true,
            fonts: [
                bunny("Instrument Sans", {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        inertia({
            ssr: false,
        }),
        react(),
        tailwindcss(),
    ],

    server: {
        host: "0.0.0.0",
        port: 5173,
        strictPort: true,

        watch: {
            ignored: ["**/storage/framework/views/**"],
        },
    },
});
