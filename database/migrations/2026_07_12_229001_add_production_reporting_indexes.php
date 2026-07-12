<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فهارس إنتاجية للاستعلامات الساخنة (Production Readiness).
 *
 * إضافية فقط — تغطّي أعمدة التاريخ/الحالة التي تفلتر عليها التقارير ولوحات KPI
 * (ADR-042/047) وحوكمة التسويق. بلا فهارس تصبح كل لوحة مسحًا كاملًا على بيانات الإنتاج.
 * لا تغيير على البيانات ولا على أي منطق.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index('created_at', 'orders_created_at_idx');
            $table->index('delivered_at', 'orders_delivered_at_idx');
        });

        Schema::table('delivery_settlements', function (Blueprint $table) {
            $table->index(['status', 'posted_at'], 'settlements_status_posted_idx');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->index(['delivery_status', 'created_at'], 'shipments_delivery_status_created_idx');
            $table->index('closed_at', 'shipments_closed_at_idx');
        });

        Schema::table('return_requests', function (Blueprint $table) {
            $table->index('created_at', 'return_requests_created_at_idx');
        });

        Schema::table('commission_entries', function (Blueprint $table) {
            $table->index('created_at', 'commission_entries_created_at_idx');
        });

        Schema::table('campaign_messages', function (Blueprint $table) {
            $table->index('created_at', 'campaign_messages_created_at_idx');
            $table->index(['customer_id', 'status', 'created_at'], 'campaign_messages_freq_idx');
        });

        Schema::table('message_suppressions', function (Blueprint $table) {
            $table->index(['contact', 'channel'], 'suppressions_contact_channel_idx');
        });

        Schema::table('ai_generation_logs', function (Blueprint $table) {
            $table->index('created_at', 'ai_logs_created_at_idx');
        });

        Schema::table('recommendation_events', function (Blueprint $table) {
            $table->index('created_at', 'reco_events_created_at_idx');
        });

        Schema::table('carts', function (Blueprint $table) {
            $table->index(['status', 'updated_at'], 'carts_status_updated_idx');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropIndex('orders_created_at_idx'));
        Schema::table('orders', fn (Blueprint $t) => $t->dropIndex('orders_delivered_at_idx'));
        Schema::table('delivery_settlements', fn (Blueprint $t) => $t->dropIndex('settlements_status_posted_idx'));
        Schema::table('shipments', fn (Blueprint $t) => $t->dropIndex('shipments_delivery_status_created_idx'));
        Schema::table('shipments', fn (Blueprint $t) => $t->dropIndex('shipments_closed_at_idx'));
        Schema::table('return_requests', fn (Blueprint $t) => $t->dropIndex('return_requests_created_at_idx'));
        Schema::table('commission_entries', fn (Blueprint $t) => $t->dropIndex('commission_entries_created_at_idx'));
        Schema::table('campaign_messages', fn (Blueprint $t) => $t->dropIndex('campaign_messages_created_at_idx'));
        Schema::table('campaign_messages', fn (Blueprint $t) => $t->dropIndex('campaign_messages_freq_idx'));
        Schema::table('message_suppressions', fn (Blueprint $t) => $t->dropIndex('suppressions_contact_channel_idx'));
        Schema::table('ai_generation_logs', fn (Blueprint $t) => $t->dropIndex('ai_logs_created_at_idx'));
        Schema::table('recommendation_events', fn (Blueprint $t) => $t->dropIndex('reco_events_created_at_idx'));
        Schema::table('carts', fn (Blueprint $t) => $t->dropIndex('carts_status_updated_idx'));
    }
};
