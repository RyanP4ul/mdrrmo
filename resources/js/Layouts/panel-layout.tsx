import AppLayout from "./AppLayout";

export default function PanelLayout({
    children,
}: {
    children: React.ReactNode;
}) {
    return (
        <>
            <AppLayout>
                <div className="m-3">{children}</div>
            </AppLayout>
        </>
    );
}
