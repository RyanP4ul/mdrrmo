import { configureEcho } from "@laravel/echo-react";
import { createInertiaApp, type ResolvedComponent } from "@inertiajs/react";
import { createRoot } from "react-dom/client";

if (import.meta.env.VITE_REVERB_APP_KEY) {
    configureEcho({
        broadcaster: "reverb",
        wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT || 8080),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT || 443),
        forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}

const appName = "MDDRMO";

createInertiaApp({
    title: (title) => `${title ? `${title} - ` : ""}${appName}`,
    resolve: async (name) => {
        const pages = import.meta.glob<{ default: ResolvedComponent }>("./Pages/**/*.tsx");

        const loadPage = pages[`./Pages/${name}.tsx`];
        if (!loadPage) {
            throw new Error(`Unknown Inertia page: ${name}`);
        }

        // console.log("Page name:", name);

        // if (name !== "Login") {
        //     page.default.layout ??= (page: React.ReactNode) => (
        //         <AppLayout>{page}</AppLayout>
        //     );
        // }

        return (await loadPage()).default;
    },

    setup({ el, App, props }) {
        if (!el) {
            throw new Error("Inertia did not provide a mount element.");
        }

        createRoot(el).render(<App {...props} />);
    },
});
