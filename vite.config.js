import { defineConfig } from "vite";
import laravel from "laravel-vite-plugin";
import { bunny } from "laravel-vite-plugin/fonts";
import tailwindcss from "@tailwindcss/vite";
import react from "@vitejs/plugin-react";
import inertia from "@inertiajs/vite";

export default defineConfig({
    plugins: [
        laravel({
            input: ["resources/css/app.css", "resources/js/app.tsx"],
            // ["resources/css/app.css", "resources/js/app.js"]
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

		// Allow Laravel application to load Vite assets
		cors: {
			origin: [
				"http://localhost:8000",
				"http://127.0.0.1:8000",
				"http://192.168.0.103:8000",
			],
		},

		hmr: {
			host: "192.168.0.103",
			protocol: "ws",
			clientPort: 5173,
		},

		watch: {
			ignored: ["**/storage/framework/views/**"],
		},
	},

});
