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

                /**
                 * هوية المتجر: بنفسجي توفير أونلاين + أصفر مساند.
                 *
                 * مقياسان مستقلّان لا يمسّان `emerald` — فاللوحة الإدارية تبقى خضراء
                 * والمتجر يصير بنفسجيًا، والملفّان يتقاسمان إعداد Tailwind واحدًا.
                 */
                brand: {
                    50: '#F4EDFA',
                    100: '#EBDFF6',
                    200: '#D7BFED',
                    300: '#BB94DF',
                    400: '#9A63CB',
                    500: '#7E3EB6',
                    600: '#6B2AA8',
                    700: '#58218B',
                    800: '#4E1F73',
                    900: '#3C1959',
                    950: '#260F38',
                },
                gold: {
                    50: '#FFF9E3',
                    100: '#FFF2C2',
                    200: '#FFE68A',
                    300: '#FFD23F',
                    400: '#F7C01F',
                    500: '#DFA50E',
                    600: '#B8820A',
                    700: '#91650C',
                    800: '#745110',
                    900: '#604313',
                },
            },
        },
    },

    plugins: [forms],
};
