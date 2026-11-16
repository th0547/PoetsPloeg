import defaultTheme from 'tailwindcss/defaultTheme';
import colors from 'tailwindcss/colors';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        // Ook klassen die in JavaScript (Alpine-componenten) of in modellen worden samengesteld.
        './resources/js/**/*.js',
        './app/Models/*.php',
        './app/Support/*.php',
    ],

    theme: {
        extend: {
            colors: {
                // Eén grijstint voor de hele app: iets koeler en rustiger dan het standaardgrijs.
                gray: colors.slate,
            },
            fontFamily: {
                sans: ['"Inter Variable"', 'Inter', ...defaultTheme.fontFamily.sans],
            },
            boxShadow: {
                card: '0 1px 2px 0 rgb(15 23 42 / 0.04), 0 1px 3px 0 rgb(15 23 42 / 0.06)',
                pop: '0 10px 30px -8px rgb(15 23 42 / 0.25), 0 2px 6px -1px rgb(15 23 42 / 0.08)',
            },
        },
    },

    plugins: [forms],
};
