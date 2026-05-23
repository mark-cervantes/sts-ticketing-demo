export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
    };
};

export type Priority = 'low' | 'medium' | 'high' | 'critical';

export type Visibility = 'private' | 'public';

export interface Category {
    id: number;
    name: string;
    slug: string;
}

export interface CreateIssueForm {
    title: string;
    description: string;
    priority: Priority;
    category_id: number | null;
    deadline_at: string | null;
    visibility: Visibility;
}

export interface Issue {
    id: number;
    title: string;
    description: string;
    priority: Priority;
    visibility: Visibility;
    status: string;
    category_id: number | null;
    category: Category | null;
    deadline_at: string | null;
    created_at: string;
    updated_at: string;
}

export interface ValidationErrors {
    [field: string]: string[];
}
