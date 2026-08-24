import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                // VODO brand palette, taken from the logo: deep teal (right ring) as the
                // primary action color, warm orange/amber (left ring) as the accent color.
                brand: {
                    50: '#eefbfb',
                    100: '#d3f4f4',
                    200: '#a8e6e6',
                    300: '#71d0d1',
                    400: '#3fb2b4',
                    500: '#1f9598',
                    600: '#127277',
                    700: '#0f5d61',
                    800: '#124a4d',
                    900: '#123e40',
                },
                accent: {
                    50: '#fff8ec',
                    100: '#ffecc7',
                    200: '#ffd889',
                    300: '#ffbe4c',
                    400: '#faa531',
                    500: '#f3841e',
                    600: '#e2631a',
                    700: '#bb4718',
                    800: '#95381c',
                    900: '#7a301b',
                },
            },
        },
    },

    plugins: [forms],
};
