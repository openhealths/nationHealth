<?php

declare(strict_types=1);

use App\Enums\Person\AuthenticationMethod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('authentication_methods', static function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique()->nullable();
            $table->enum('type', AuthenticationMethod::values());
            // Here need to be text type because of the length of url commonly exceed the 255 characters
            $table->text('url')->nullable();
            $table->string('phone_number')->nullable();
            $table->string('value')->nullable();
            $table->string('alias')->nullable();
            $table->timestampTz('ehealth_ended_at')->nullable();
            // default index name is too long for mysql
            $table->morphs('authenticatable', 'am_type_am_id_index');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('authentication_methods');
    }
};
