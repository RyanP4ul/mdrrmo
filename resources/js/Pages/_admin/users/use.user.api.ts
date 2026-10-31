import {
    CreateUserInput,
    LaravelPaginator,
    UpdateUserInput,
    UserRow,
    UsersQuery,
} from "@/types/user";
import { requestJson } from "../../../lib/api";

export async function fetchUsers(filters: UsersQuery, signal?: AbortSignal) {
    const params = new URLSearchParams({
        page: String(filters.page),
        per_page: String(filters.perPage),
    });
    if (filters.search?.trim()) params.set("search", filters.search.trim());
    if (filters.role) params.set("role", filters.role);
    if (filters.status) params.set("status", filters.status);

    const result: any = await requestJson(`/api/users?${params.toString()}`, {
        method: "GET",
        signal,
    });

    return result?.original as LaravelPaginator<UserRow>;
}

export async function createUser(input: CreateUserInput): Promise<UserRow> {
    const response = await requestJson<{ data: UserRow }>("/api/users", {
        method: "POST",
        body: JSON.stringify(input),
    });
    return response.data;
}

export async function updateUser(
    id: UserRow["id"],
    input: UpdateUserInput,
): Promise<UserRow> {
    const response = await requestJson<{ data: UserRow }>(
        `/api/users/${encodeURIComponent(String(id))}`,
        {
            method: "PUT",
            body: JSON.stringify(input),
        },
    );
    return response.data;
}

export async function deleteUser(id: UserRow["id"]): Promise<void> {
    await requestJson<void>(`/api/users/${encodeURIComponent(String(id))}`, {
        method: "DELETE",
    });
}
