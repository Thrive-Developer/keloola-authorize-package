<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('keloola_sso_organizations', function (Blueprint $table) {
            $table->uuid('sso_id')->primary();
            $table->string('name');
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->uuid('owner_sso_id');
            $table->timestamps();
        });
        Schema::create('keloola_sso_users', function (Blueprint $table) {
            $table->uuid('sso_id')->primary();
            $table->string('name');
            $table->string('email');
            $table->timestamps();
        });
        Schema::create('keloola_sso_roles', function (Blueprint $table) {
            $table->id();
            $table->uuid('organization_id');
            $table->string('sso_role_id', 128)->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('permissions');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_delete')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'sso_role_id'], 'keloola_sso_roles_source_unique');
            $table->foreign('organization_id')->references('sso_id')->on('keloola_sso_organizations');
        });
        Schema::create('keloola_sso_memberships', function (Blueprint $table) {
            $table->uuid('organization_id');
            $table->uuid('user_id');
            $table->unsignedBigInteger('role_id');
            $table->timestamps();
            $table->primary(['organization_id', 'user_id'], 'keloola_sso_membership_primary');
            $table->foreign('organization_id')->references('sso_id')->on('keloola_sso_organizations');
            $table->foreign('user_id')->references('sso_id')->on('keloola_sso_users');
            $table->foreign('role_id')->references('id')->on('keloola_sso_roles');
        });
        Schema::create('keloola_sso_invitations', function (Blueprint $table) {
            $table->string('sso_id', 128)->primary();
            // Nullable fields allow cancellation tombstones before a delayed create.
            $table->uuid('organization_id')->nullable();
            $table->string('email')->nullable();
            $table->unsignedBigInteger('role_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('status');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keloola_sso_invitations');
        Schema::dropIfExists('keloola_sso_memberships');
        Schema::dropIfExists('keloola_sso_roles');
        Schema::dropIfExists('keloola_sso_users');
        Schema::dropIfExists('keloola_sso_organizations');
    }
};
