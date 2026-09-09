<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('message_attachments', 'retention_class')) {
            Schema::table('message_attachments', function (Blueprint $table) {
                $table->string('retention_class', 32)->default('ephemeral')->after('kind');
                $table->timestamp('expires_at')->nullable()->after('retention_class');
                $table->string('summary_status', 32)->default('pending')->after('expires_at');
                $table->text('summary_text')->nullable()->after('summary_status');
                $table->timestamp('summarized_at')->nullable()->after('summary_text');
                $table->timestamp('purged_at')->nullable()->after('summarized_at');
                $table->unsignedTinyInteger('purge_failure_count')->default(0)->after('purged_at');

                $table->index(['retention_class', 'expires_at', 'summary_status'], 'msg_att_retention_expiry_idx');
                $table->index(['purged_at']);
            });
        }

        $hours = max(1, (int) config('chat_attachments.retention_hours', 24));

        $expiresSql = DB::getDriverName() === 'sqlite'
            ? "datetime(created_at, '+{$hours} hours')"
            : 'DATE_ADD(created_at, INTERVAL '.$hours.' HOUR)';

        DB::table('message_attachments')
            ->whereNull('expires_at')
            ->update([
                'retention_class' => 'ephemeral',
                'summary_status' => 'pending',
                'expires_at' => DB::raw($expiresSql),
            ]);
    }

    public function down(): void
    {
        Schema::table('message_attachments', function (Blueprint $table) {
            $table->dropIndex('msg_att_retention_expiry_idx');
            $table->dropIndex(['purged_at']);
            $table->dropColumn([
                'retention_class',
                'expires_at',
                'summary_status',
                'summary_text',
                'summarized_at',
                'purged_at',
                'purge_failure_count',
            ]);
        });
    }
};
