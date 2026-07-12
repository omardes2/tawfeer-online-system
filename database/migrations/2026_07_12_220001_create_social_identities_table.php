<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * هويّات تسجيل الدخول الاجتماعي (Phase 3.5 / ADR-036). تُخزَّن معرّفات المزوّد
 * منفصلة عن حساب العميل. حساب واحد قد يربط عدّة مزوّدين؛ (provider, provider_id) فريد
 * لمنع تكرار الحسابات. لا تُخزَّن أسرار المزوّد هنا (الأسرار في .env فقط).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider', 30);       // google / facebook
            $table->string('provider_id');        // معرّف المستخدم لدى المزوّد
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->string('avatar')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_identities');
    }
};
