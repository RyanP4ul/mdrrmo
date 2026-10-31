export type LaravelPaginator<T> = {
    current_page: number;
    data: T[];
    first_page_url: string | null;
    from: number | null;
    last_page: number;
    last_page_url: string | null;
    links: Array<{ url: string | null; label: string; active: boolean }>;
    next_page_url: string | null;
    path: string;
    per_page: number;
    prev_page_url: string | null;
    to: number | null;
    total: number;
};

type LaravelErrorPayload = {
    message?: string;
    errors?: Record<string, string[]>;
};

export class ApiError extends Error {
    status: number;
    errors?: Record<string, string[]>;

    constructor(
        message: string,
        status: number,
        errors?: Record<string, string[]>,
    ) {
        super(message);
        this.name = "ApiError";
        this.status = status;
        this.errors = errors;
    }
}

function getXsrfCookie(): string | undefined {
    if (typeof document === "undefined") return undefined;
    const cookie = document.cookie
        .split("; ")
        .find((part) => part.startsWith("XSRF-TOKEN="));
    if (!cookie) return undefined;
    const rawValue = cookie.slice("XSRF-TOKEN=".length);
    try {
        return decodeURIComponent(rawValue);
    } catch {
        return rawValue;
    }
}

export async function requestJson<T>(
    url: string,
    init: RequestInit = {},
): Promise<T> {
    const headers = new Headers(init.headers);
    headers.set("Accept", "application/json");
    headers.set("X-Requested-With", "XMLHttpRequest");

    if (init.body !== undefined && !headers.has("Content-Type")) {
        headers.set("Content-Type", "application/json");
    }

    const method = (init.method ?? "GET").toUpperCase();
    if (method !== "GET" && method !== "HEAD") {
        const metaToken =
            typeof document !== "undefined"
                ? document.querySelector<HTMLMetaElement>(
                      'meta[name="csrf-token"]',
                  )?.content
                : undefined;
        const xsrfToken = getXsrfCookie();
        if (metaToken) headers.set("X-CSRF-TOKEN", metaToken);
        else if (xsrfToken) headers.set("X-XSRF-TOKEN", xsrfToken);
    }

    const response = await fetch(url, {
        ...init,
        headers,
        credentials: "same-origin",
    });

    if (response.status === 204) return undefined as T;

    let payload: unknown;
    try {
        payload = await response.json();
    } catch {
        payload = undefined;
    }

    if (!response.ok) {
        const body = (payload ?? {}) as LaravelErrorPayload;
        throw new ApiError(
            body.message ?? `Request failed with HTTP ${response.status}.`,
            response.status,
            body.errors,
        );
    }

    return payload as T;
}
