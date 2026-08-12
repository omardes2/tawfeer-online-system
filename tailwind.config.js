import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
    ],

    theme: {
        extend: {
            fontFamily: {
                // Tajawal هو خط الواجهة العربية المحمَّل في التخطيط؛ سبقه هنا يمنع
                // اختلاف شكل النص بين ما تحدّده الأنماط وما يرثه `font-sans`.
                sans: ['Tajawal', 'Noto Sans Arabic', ...defaultTheme.fontFamily.sans],
            },

            colors: {
                /**
                 * أخضر توفير — يحلّ محلّ `emerald` الافتراضي، فترث كل الصفحات
                 * القائمة الهوية الجديدة دون تعديل صفحة صفحة. الدرجة 600 هي لون
                 * العلامة (#0B7A52): أعمق من الافتراضي وتبايُنه مع الأبيض أعلى.
                 */
                emerald: {
                    50: '#ECF7F1',
                    100: '#D3EDE0',
                    200: '#A8DCC3',
                    300: '#74C4A1',
                    400: '#3FA87D',
                    500: '#158E62',
                    600: '#0B7A52',
                    700: '#086342',
                    800: '#084F36',
                    900: '#07412D',
                    950: '#03251A',
                },

                /** الشريط الجانبي: أسود مائل للأخضر بدل الرمادي المحايد. */
                rail: {
                    DEFAULT: '#0C1A14',
                    2: '#122419',
                    line: '#1E332A',
                    ink: '#A8BCB1',
                },
            },
        },
    },

    plugins: [forms],
};
