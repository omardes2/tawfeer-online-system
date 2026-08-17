<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ربط رسالة الحملة بجهة اتصالٍ تسويقية، لا بعميلٍ وحده.
 *
 * `customer_id` وحده كان يكفي حين كانت الحملات تُرسَل لمن عاملك. أمّا قائمة
 * الأرقام فأكثرُها ليسوا عملاء — راسلوا ولم يشتروا — ولا سبيل لتسجيل ما أُرسل
 * إليهم بلا هذا العمود. وبدونه لا يُعرَف من راسلناه، فتتكرّر الرسالة عليه في
 * كل تشغيلة.
 *
 * والعمودان معًا: من كان عميلًا يُملأ له الاثنان، ومن لم يكن يُملأ له هذا وحده.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_messages', function (Blueprint $table) {
            $table->foreignId('marketing_contact_id')->nullable()->after('customer_id')
                ->constrained('marketing_contacts')->nullOnDelete();

            $table->index(['marketing_contact_id', 'campaign_id'], 'campaign_messages_contact');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_messages', function (Blueprint $table) {
            $table->dropIndex('campaign_messages_contact');
            $table->dropConstrainedForeignId('marketing_contact_id');
        });
    }
};
