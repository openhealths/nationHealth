<?php

use App\Enums\JobStatus;
use App\Enums\ResponseStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected const array VALID_STATUSES = [
        'SYNC',
        'ASYNC'
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
          Schema::create('ehealth_jobs', function (Blueprint $table) {
            $table->id();

            $table->enum('processing_method', ResponseStatus::only(self::VALID_STATUSES))->default(ResponseStatus::ASYNC)->comment('201 - sync, 202 - async processing method code');
            $table->enum('status', JobStatus::values())->nullable();
            
            $table->json('request_data')->nullable()->comment('original request data sent to eHealth API');
            $table->json('response_data')->nullable()->comment('response data received from eHealth API');
            
            $table->timestamp('eta')->nullable()->comment('estimated time of arrival');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ehealth_jobs');
    }
};
