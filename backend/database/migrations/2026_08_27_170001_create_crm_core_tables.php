<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_code_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('last_value')->default(0);
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('customer_code')->unique();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('phone');
            $table->index('status');
            $table->index('email');
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2);
            $table->char('currency', 3);
            $table->unsignedInteger('duration_days');
            $table->unsignedInteger('max_devices');
            $table->unsignedInteger('speed_limit_mbps')->nullable();
            $table->unsignedBigInteger('traffic_limit_bytes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('active');
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('status', 20);
            $table->timestamp('starts_at');
            $table->timestamp('expires_at');
            $table->string('source', 30)->default('MANUAL');
            $table->boolean('auto_renew')->default(false);
            $table->unsignedInteger('custom_max_devices')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index('expires_at');
        });

        Schema::create('activation_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('key_prefix', 16);
            $table->string('key_hash');
            $table->string('status', 20)->default('ACTIVE');
            $table->unsignedInteger('max_activations')->default(1);
            $table->unsignedInteger('activation_count')->default(0);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index('key_prefix');
        });

        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->uuid('device_uuid');
            $table->string('platform', 10);
            $table->string('device_name');
            $table->string('os_version')->nullable();
            $table->string('app_version')->nullable();
            $table->string('device_token_hash')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'device_uuid']);
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
        Schema::dropIfExists('activation_keys');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('customer_code_sequences');
    }
};
