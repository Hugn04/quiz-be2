<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nhật ký thao tác quản trị: ai làm gì, với cái gì, lúc nào.
 * Lưu kèm tên người làm / tên đối tượng (snapshot) để vẫn đọc được khi đã bị xóa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('actor_role', 30)->nullable();
            $table->string('action', 50);             // vd user.ban, exam.delete
            $table->string('target_type', 30)->nullable(); // user | exam | sector | report | settings | images
            $table->string('target_id', 100)->nullable();  // uuid / slug / id
            $table->string('target_label')->nullable();
            $table->json('meta')->nullable();         // chi tiết (role cũ → mới, lý do...)
            $table->timestamp('created_at')->useCurrent();

            $table->index(['created_at']);
            $table->index(['action', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_logs');
    }
};
