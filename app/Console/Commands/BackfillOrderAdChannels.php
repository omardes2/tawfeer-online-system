<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Marketing\Models\AdChannel;
use App\Modules\Sales\Models\Order;
use Illuminate\Console\Command;

/**
 * ملء قناة الإعلان للطلبات السابقة من الربط القائم (موظفة ← حساب بزنس ← صفحة).
 *
 * لمرّةٍ واحدة بعد ربط القنوات بحسابات البزنس. الطلبات الجديدة تُثبَّت قناتُها
 * لحظة الإنشاء، فلا حاجة لتشغيله دوريًّا. ولا يمسّ طلبًا له قناة أصلًا: اللقطة
 * المثبَّتة تاريخٌ وقع، لا تُعاد كتابتُه من الحاضر.
 */
class BackfillOrderAdChannels extends Command
{
    protected $signature = 'ads:backfill-order-channels {--dry-run : عرض ما سيتغيّر بلا كتابة}';

    protected $description = 'ملء قناة الإعلان للطلبات السابقة من حساب البزنس الخاص بمنشئ الطلب';

    public function handle(): int
    {
        $channels = AdChannel::whereNotNull('delivery_business_id')->pluck('id', 'delivery_business_id');

        if ($channels->isEmpty()) {
            $this->error('لا توجد قناة مرتبطة بحساب بزنس. اربط القنوات أولًا من «الإعدادات ← قنوات الإعلان».');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $total = 0;

        foreach ($channels as $businessId => $channelId) {
            $users = User::where('delivery_business_id', $businessId)->pluck('id');

            if ($users->isEmpty()) {
                continue;
            }

            $query = Order::withTrashed()->whereNull('ad_channel_id')->whereIn('created_by', $users);
            $count = (clone $query)->count();

            if (! $dry && $count > 0) {
                $query->update(['ad_channel_id' => $channelId]);
            }

            $name = AdChannel::whereKey($channelId)->value('name');
            $this->line(sprintf('%s: %d طلبًا', $name, $count));
            $total += $count;
        }

        $this->info($dry
            ? "لم يُكتب شيء (تجربة). المرشَّح للتحديث: {$total} طلبًا."
            : "اكتمل. حُدِّث {$total} طلبًا.");

        return self::SUCCESS;
    }
}
