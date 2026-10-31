import { usePage } from "@inertiajs/react";

export default function Page() {
    const { auth } = usePage().props;

    return <h1>Content: {JSON.stringify(auth)}</h1>;
}
