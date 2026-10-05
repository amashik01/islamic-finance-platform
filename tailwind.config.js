import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** Design tokens: deep emerald primary, restrained gold accent, warm neutrals. */
/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './vendor/livewire/livewire/src/Features/SupportPagination/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './app/Enums/*.php',
    ],

    theme: {
        extend: {
            colors: {
                brand: {
                    50: '#ecf8f3', 100: '#d1efe3', 200: '#a5dfc9', 300: '#6fc8a9', 400: '#3dab87',
                    500: '#1f8d6c', 600: '#127256', 700: '#0d5c46', 800: '#0b4a3a', 900: '#083a2e', 950: '#04231c',
                },
                gold: { 50: '#fbf7ec', 100: '#f5ebcc', 200: '#ebd69b', 300: '#dfbd66', 400: '#d3a63f', 500: '#b98a2a', 600: '#966c21', 700: '#74521d' },
                ink: { 50: '#f7f6f3', 100: '#efede8', 200: '#dedbd3', 300: '#c3bfb4', 400: '#9a968a', 500: '#77736a', 600: '#5b5851', 700: '#46443f', 800: '#2e2d2a', 900: '#1d1c1a' },
            },
            fontFamily: {
                sans: ['Inter', 'Figtree', ...defaultTheme.fontFamily.sans],
                display: ['"Fraunces"', 'Georgia', ...defaultTheme.fontFamily.serif],
            },
            borderRadius: { card: '1rem', control: '0.625rem' },
            boxShadow: {
                card: '0 1px 2px rgba(29,28,26,.04), 0 4px 16px rgba(29,28,26,.05)',
                lift: '0 8px 30px rgba(8,58,46,.12)',
            },
            spacing: { sidebar: '17rem' },
        },
    },

    plugins: [forms],
};
