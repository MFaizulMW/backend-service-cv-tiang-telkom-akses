<?php

namespace App\Drivers\TelkomApi;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Mock Telkom API driver backed by Supabase.
 *
 * Used for development/demo when the real Telkom External API is not yet accessible.
 * Demonstrates the pluggable driver pattern — zero changes to business logic.
 *
 * Setup:
 *   1. Create a Supabase project
 *   2. Create table `photos` with columns:
 *        photo_id (text, primary key)
 *        photo_url (text)         ← URL from Supabase Storage (public bucket)
 *        category (text)          ← fill with "tiang"
 *        captured_date (date)     ← YYYY-MM-DD
 *        location (text, nullable)
 *        captured_at (timestamptz, nullable)
 *   3. Upload tiang photos to Supabase Storage (public bucket)
 *   4. Insert rows into the `photos` table
 *   5. Set .env:
 *        TELKOM_API_AUTH_DRIVER=supabase
 *        SUPABASE_URL=https://xxxx.supabase.co
 *        SUPABASE_ANON_KEY=eyJ...
 *        TELKOM_API_PHOTO_CATEGORY=tiang
 */
class SupabaseMockDriver implements TelkomApiInterface
{
    private string $supabaseUrl;
    private string $anonKey;
    private string $photoCategory;
    private string $tableName;

    public function __construct()
    {
        $this->supabaseUrl   = rtrim((string) config('telkom.supabase.url'), '/');
        $this->anonKey       = (string) config('telkom.supabase.anon_key');
        $this->photoCategory = (string) config('telkom.api.photo_category', 'tiang');
        $this->tableName     = (string) config('telkom.supabase.table', 'photos');
    }

    /**
     * Fetch photos from Supabase table for a given date.
     * Supabase PostgREST uses filter syntax: column=eq.value
     */
    public function fetchPhotos(string $date): array
    {
        $response = Http::withHeaders([
            'apikey'        => $this->anonKey,
            'Authorization' => "Bearer {$this->anonKey}",
        ])
        ->timeout(30)
        ->get("{$this->supabaseUrl}/rest/v1/{$this->tableName}", [
            'category'       => "eq.{$this->photoCategory}",
            'captured_date'  => "eq.{$date}",
            'select'         => 'photo_id,photo_url,category,location,captured_at',
        ]);

        $response->throw();

        // Supabase returns a plain array — normalize to expected format
        return array_map(fn($row) => [
            'photo_id'  => $row['photo_id'],
            'photo_url' => $row['photo_url'],
            'category'  => $row['category'],
            'metadata'  => [
                'location'    => $row['location'] ?? null,
                'captured_at' => $row['captured_at'] ?? null,
            ],
        ], $response->json() ?? []);
    }

    /**
     * No-op callback for mock driver — Supabase is not the real Telkom system.
     */
    public function sendCallback(string $photoId, array $result): void
    {
        Log::info('SupabaseMockDriver: callback suppressed (not a real Telkom endpoint)', [
            'photo_id' => $photoId,
        ]);
    }
}
