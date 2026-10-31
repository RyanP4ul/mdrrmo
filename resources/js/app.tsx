import { configureEcho } from "@laravel/echo-react";
import { createInertiaApp } from "@inertiajs/react";
import { createRoot } from "react-dom/client";

import AppLayout from "./Layouts/AppLayout";
import PanelLayout from "./Layouts/panel-layout";

configureEcho({
    broadcaster: "reverb",
});

const appName = "MDDRMO";

createInertiaApp({
    title: (title) => `${title ? `${title} - ` : ""}${appName}`,
    resolve: (name) => {
        const pages = import.meta.glob("./Pages/**/*.tsx", {
            eager: true,
        });

        const page = pages[`./Pages/${name}.tsx`] as {
            default: any;
        };

        // console.log("Page name:", name);

        // if (name !== "Login") {
        //     page.default.layout ??= (page: React.ReactNode) => (
        //         <AppLayout>{page}</AppLayout>
        //     );
        // }

        return page;
    },

    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
});
