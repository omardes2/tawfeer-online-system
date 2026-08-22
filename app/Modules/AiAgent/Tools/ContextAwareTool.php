<?php

namespace App\Modules\AiAgent\Tools;

use App\Modules\Messaging\Models\Conversation;

/**
 * أداةٌ تحتاج أن تعرف **في أيّ محادثة** تعمل.
 *
 * أدوات القراءة (بحث، سعر، مخزون) لا تحتاج ذلك: سؤالها مكتفٍ بمعاملاته. أمّا
 * من يكتب — كإنشاء طلب — فيحتاج هوية الزبون، ورقمُه يجب أن يأتي من **جهة
 * الاتصال** لا من النموذج: رقمٌ يمليه النموذج رقمٌ قابل للاختلاق، وطلبٌ
 * برقمٍ مخترع يصل إلى شخصٍ آخر.
 */
interface ContextAwareTool
{
    public function setConversation(Conversation $conversation): void;
}
