<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zoom_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('external_event_key');
            $table->string('event_name');
            $table->string('account_id')->nullable();
            $table->string('meeting_uuid')->nullable();
            $table->string('meeting_numeric_id')->nullable();
            $table->string('recording_file_id')->nullable();
            $table->string('status');
            $table->foreignId('meeting_id')->nullable()->constrained('meetings')->nullOnDelete();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('error_class')->nullable();
            $table->string('error_message')->nullable();
            $table->json('payload_subset')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique('external_event_key');
            $table->index(['event_name', 'status']);
            $table->index(['meeting_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zoom_webhook_events');
    }
};
