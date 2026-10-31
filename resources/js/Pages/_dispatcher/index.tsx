import { usePage } from "@inertiajs/react";

export default function Page() {
    const { auth } = usePage().props;

    return (
        <div>
            <h1>Dispatcher</h1>
            {JSON.stringify(auth)}
        </div>
    );
}
