<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * هوية المُراسِل على القناة — منفصلةٌ عن `customers` عمدًا.
 *
 * المحادثة تسبق العميل: من يسأل عن منتجٍ على واتساب ليس عميلًا بعد، وقد لا
 * يصير. وربطُه بـ`customers` من اللحظة الأولى يملأ قاعدة العملاء بمن لم يشترِ
 * ويُفسد كل عدٍّ وتقرير. فيُربط `customer_id` عند أول طلبٍ أو بمطابقة الرقم.
 *
 * و`last_inbound_at` ليس حقلًا إحصائيًّا: عليه وحده تُقاس **نافذة الأربع
 * والعشرين ساعة** التي تسمح بها ميتا للنصّ الحرّ. وبدونه يُرفض كل إرسالٍ أو
 * يُحظر الرقم.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained('messaging_channels')->cascadeOnDelete();
            $table->string('external_id', 100);          // رقم واتساب بصيغة E.164
            $table->string('display_name', 150)->nullable();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamps();

            $table->unique(['channel_id', 'external_id']);
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_contacts');
    }
};
