<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_accounts', function (Blueprint $table): void {
            $table->string('display_label')->nullable()->after('external_account_email');
            $table->boolean('enabled')->default(true)->after('status');
            $table->string('health', 32)->default('disabled')->after('enabled');
            $table->string('last_error_message')->nullable()->after('last_error_code');
            $table->timestamp('last_event_at')->nullable()->after('last_success_at');
            $table->timestamp('last_processed_at')->nullable()->after('last_event_at');
        });

        Schema::table('project_source_bindings', function (Blueprint $table): void {
            $table->string('binding_kind', 16)->default('explicit')->after('source_id');
        });

        Schema::create('source_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_account_id')->nullable()->constrained('integration_accounts')->nullOnDelete();
            $table->string('source_type', 32);
            $table->string('source_instance', 80);
            $table->string('external_id', 191);
            $table->string('thread_id', 191)->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('subject')->nullable();
            $table->string('snippet', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'source_type', 'source_instance', 'external_id'], 'source_items_dedupe');
            $table->index(['user_id', 'source_type', 'occurred_at']);
            $table->index(['user_id', 'project_id']);
            $table->index(['user_id', 'person_id']);
        });

        foreach (DB::table('integration_accounts')->select([
            'id',
            'status',
            'display_label',
            'external_account_email',
        ])->get() as $row) {
            $status = (string) $row->status;
            $health = match ($status) {
                'connected' => 'healthy',
                'error', 'revoked' => 'blocked',
                default => 'disabled',
            };

            DB::table('integration_accounts')->where('id', $row->id)->update([
                'enabled' => $status === 'connected' ? 1 : 0,
                'health' => $health,
                'display_label' => $row->display_label ?: $row->external_account_email,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('source_items');

        Schema::table('project_source_bindings', function (Blueprint $table): void {
            $table->dropColumn('binding_kind');
        });

        Schema::table('integration_accounts', function (Blueprint $table): void {
            $table->dropColumn([
                'display_label',
                'enabled',
                'health',
                'last_error_message',
                'last_event_at',
                'last_processed_at',
            ]);
        });
    }
};
