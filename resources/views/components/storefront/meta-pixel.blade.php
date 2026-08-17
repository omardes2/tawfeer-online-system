{{--
    بكسل ميتا — الجزء الذي يعمل في المتصفّح.

    نصفُ القياس لا كلُّه: حدث **الشراء** يُرسَل من الخادم (Conversions API) لأن
    مسار الإتمام محميّ لا يُمسّ، ولأن مانعات الإعلانات تُسقط جزءًا كبيرًا من
    أحداث المتصفّح والشراء أهمّ من أن يُترك لها. أمّا هنا فالزيارة ومشاهدة
    الصنف — وهما إشارتان لا تُغني عنهما الخلفية لأنهما تحملان هويّة المتصفّح
    (`_fbp`) التي تُقوّي المطابقة.

    ولا يُطبَع شيء بلا معرّف بكسل مضبوط: المتجر يعمل كاملًا بلا قياس.
--}}
@php $pixelId = config('ads.pixel.id'); @endphp

@if (filled($pixelId))
    <script>
        !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
        n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
        n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
        t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
        document,'script','https://connect.facebook.net/en_US/fbevents.js');
        fbq('init', @js($pixelId));
        fbq('track', 'PageView');
    </script>
    <noscript><img height="1" width="1" style="display:none"
        src="https://www.facebook.com/tr?id={{ $pixelId }}&ev=PageView&noscript=1" alt=""></noscript>

    @stack('pixel-events')
@endif
