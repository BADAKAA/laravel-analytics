import type { InertiaLinkProps } from '@inertiajs/vue3';
import { clsx } from 'clsx';
import type { ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function toUrl(href: NonNullable<InertiaLinkProps['href']>) {
    return typeof href === 'string' ? href : href?.url;
}

export function codeToName(country: string): string {
    const countryName = new Intl.DisplayNames(navigator.language, { type: 'region' });

    return countryName.of(country) ?? country;
}

export function compactNumber(value: number): string {
    return Intl.NumberFormat(navigator.language,{ notation: "compact" }).format(value);
}

export function ucfirst(str: string): string {
    const parts = str.split('_');

    return parts.map(part => part.charAt(0).toUpperCase() + part.slice(1)).join(' ');
}