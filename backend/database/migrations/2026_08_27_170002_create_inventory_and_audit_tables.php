<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->char('country_code', 2);
            $table->string('country_name');
            $table->string('city');
            $table->string('display_name');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['active', 'sort_order']);
        });

        Schema::create('vpn_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('hostname')->unique();
            $table->string('public_endpoint');
            $table->unsignedInteger('vpn_port');
            $table->string('public_key')->nullable();
            $table->unsignedInteger('capacity_users');
            $table->string('health_status', 20)->default('UNKNOWN');
            $table->string('lifecycle_status', 20)->default('ACTIVE');
            $table->boolean('maintenance_mode')->default(false);
            $table->unsignedInteger('weight')->default(100);
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['lifecycle_status', 'health_status']);
            $table->index('location_id');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payment_method', 30);
            $table->string('external_reference')->nullable();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3);
            $table->string('status', 20)->default('PENDING');
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['payment_method', 'external_reference']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type', 30);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->string('target_type');
            $table->string('target_id');
            $table->json('before_data')->nullable();
            $table->json('after_data')->nullable();
            $table->string('source_ip', 45)->nullable();
            $table->string('request_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['target_type', 'target_id']);
            $table->index(['actor_type', 'actor_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('vpn_nodes');
        Schema::dropIfExists('locations');
    }
};
