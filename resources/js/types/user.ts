export type UserRole = "admin" | "staff" | "customer" | "driver";
export type UserStatus = "active" | "inactive";

export type UserRow = {
    id: number | string;
    first_name: string;
    last_name: string;
    phone_number: string;
    email: string;
    role: UserRole;
    status: UserStatus;
    created_at: string | null;
};

export type UsersQuery = {
    page: number;
    perPage: number;
    search?: string;
    role?: UserRole;
    status?: UserStatus;
};

export type CreateUserInput = {
    first_name: string;
    last_name: string;
    phone_number: string;
    email: string;
    role: UserRole;
    status: UserStatus;
    password: string;
};

export type UpdateUserInput = Omit<CreateUserInput, "password"> & {
    password?: string;
};

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
