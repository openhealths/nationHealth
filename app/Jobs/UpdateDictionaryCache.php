<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Services\Dictionary\DictionaryManager;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class UpdateDictionaryCache implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private readonly string $dictionaryKey,
        private readonly int $pageNumber = 1,
        private readonly ?string $bearerToken = null
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(DictionaryManager $manager): void
    {
        try {
            if ($this->bearerToken) {
                session()->put(config('ehealth.api.oauth.bearer_token'), $this->bearerToken);
            }

            $response = $manager->fetchPage($this->dictionaryKey, $this->pageNumber);
            $paging = $response->getPaging();
            $currentPageData = $response->getData();

            // Get existing cache data and update it
            if ($this->pageNumber === 1) {
                // First page - replace entire cache
                $updatedData = $currentPageData;
            } else {
                // Other pages - append to existing cache
                $existingData = Cache::get($this->dictionaryKey, []);
                $updatedData = array_merge($existingData, $currentPageData);
            }

            Cache::put(
                $this->dictionaryKey,
                $updatedData,
                now()->addDays(7)
            );

            $totalPages = $paging['total_pages'] ?? 1;

            // If this is page 1 and there are more pages, dispatch jobs for other pages
            if ($this->pageNumber === 1 && $totalPages > 1) {
                for ($page = 2; $page <= $totalPages; $page++) {
                    self::dispatch($this->dictionaryKey, $page)
                        ->delay(now()->addSeconds($page * 2));
                }
            }

            // Check if this is the last page to set fresh marker
            if ($this->pageNumber === $totalPages) {
                // All pages are cached, set fresh marker
                Cache::put($this->dictionaryKey . ':fresh', true, now()->endOfDay());

                Log::info("Dictionary cache refresh completed", [
                    'dictionary' => $this->dictionaryKey,
                    'total_items' => count($updatedData)
                ]);
            }
        } catch (EHealthConnectionException $e) {
            Log::warning("Dictionary API connection failed - will retry", [
                'dictionary' => $this->dictionaryKey,
                'page' => $this->pageNumber,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage()
            ]);

            throw $e; // Will retry due to network issues
        } catch (EHealthResponseException|EHealthValidationException $e) {
            Log::error("Dictionary API error - will not retry", [
                'dictionary' => $this->dictionaryKey,
                'page' => $this->pageNumber,
                'error' => $e->getMessage(),
                'response_body' => method_exists($e, 'getResponse') ? $e->getResponse()?->body() : null
            ]);

            $this->fail($e); // Don't retry API validation errors
        } catch (Exception $e) {
            Log::error("Unexpected error in dictionary cache update", [
                'dictionary' => $this->dictionaryKey,
                'page' => $this->pageNumber,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }
}
