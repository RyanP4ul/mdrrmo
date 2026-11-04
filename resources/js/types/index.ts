export type Role = 'admin' | 'dispatcher' | 'driver/responder' | 'resident';
export type Priority = 'low' | 'medium' | 'high' | 'critical';
export type ReportStatus = 'pending' | 'acknowledged' | 'dispatched' | 'resolved' | 'invalid';

export interface AuthUser { id: number; first_name: string; last_name: string; full_name: string; email: string; role: Role }

export interface Paginated<T> {
  data: T[];
  current_page: number; last_page: number; per_page: number; total: number;
  from: number | null; to: number | null;
  prev_page_url: string | null; next_page_url: string | null;
}

export interface PageProps {
  auth: { user: AuthUser };
  flash: { success?: string; error?: string };
  [key: string]: unknown;
}
