
import Alpine from 'alpinejs';
import Chart from 'chart.js/auto';
import adminNav from './admin-nav';

// إتاحة Chart.js عالميًا لمكوّن <x-admin.chart> (رسوم تفاعلية في التقارير والتحليلات).
window.Chart = Chart;

// إعدادات افتراضية متناسقة مع هوية اللوحة (RTL + خطوط النظام).
Chart.defaults.font.family =
    "system-ui, -apple-system, 'Segoe UI', Tahoma, 'Noto Sans Arabic', sans-serif";
Chart.defaults.color = '#64748b';
Chart.defaults.plugins.legend.labels.usePointStyle = true;
Chart.defaults.maintainAspectRatio = false;

window.Alpine = Alpine;

// تنقّل اللوحة: بحث فوري + وجهات مثبّتة + قسم واحد مفتوح.
Alpine.data('adminNav', adminNav);

Alpine.start();
