import { createInertiaApp } from "@inertiajs/react";
import { createRoot } from "react-dom/client";
import { TooltipProvider } from "@/components/ui/tooltip";

createInertiaApp({
    withApp(app, { ssr }) {
        if (!ssr) {
            return <BrowserProvider>{app}</BrowserProvider>;
        }

        return app;
    },
    resolve: (name) => {
        const pages = import.meta.glob("./Pages/**/*.jsx");
        return pages[`./Pages/${name}.jsx`]();
    },
    setup({ el, App, props }) {
        createRoot(el).render(
            <TooltipProvider>
                <App {...props} />
            </TooltipProvider>,
        );
    },
});
