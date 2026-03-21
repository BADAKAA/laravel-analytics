export type SiteItem = {
    id: number;
    name: string;
    domain: string;
    timezone: string;
    is_public: boolean;
    role: number;
    role_label: string;
    can_edit: boolean;
    created_at: string | null;
    updated_at: string | null;
};

export type SiteFormPayload = {
    name: string;
    domain: string;
    timezone: string;
    is_public: boolean;
};
