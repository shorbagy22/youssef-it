<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\SharePointException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Low-level wrapper around Microsoft Graph's v1.0 API, scoped to the
 * SharePoint drive configured in config/sharepoint.php. The only class
 * that knows Graph's authentication flow, URL shapes, and JSON format -
 * SharePointExcelService builds on this without knowing any of that.
 *
 * Authenticates via the OAuth2 client credentials (app-only) flow using
 * the tenant/client ID and secret from config/sharepoint.php. The access
 * token is memoized in memory for this instance's lifetime only - nothing
 * is persisted to a cache store, and the client secret is never logged.
 */
final class MicrosoftGraphClient
{
    private const string BASE_URL = 'https://graph.microsoft.com/v1.0';

    private const int REQUEST_TIMEOUT = 30;

    private const int DOWNLOAD_TIMEOUT = 120;

    private const int RETRIES = 2;

    private const int RETRY_DELAY_MS = 250;

    private ?string $cachedToken = null;

    private int $tokenExpiresAt = 0;

    private readonly string $tenantId;

    private readonly string $clientId;

    private readonly string $clientSecret;

    private readonly string $driveId;

    private readonly string $folderPath;

    public function __construct()
    {
        $this->tenantId = (string) config('sharepoint.tenant_id');
        $this->clientId = (string) config('sharepoint.client_id');
        $this->clientSecret = (string) config('sharepoint.client_secret');
        $this->driveId = (string) config('sharepoint.drive_id');
        $this->folderPath = trim((string) config('sharepoint.folder_path'), '/');
    }

    /**
     * Whether the configured drive is reachable with the current
     * credentials.
     */
    public function healthCheck(): bool
    {
        try {
            $this->request('GET', "/drives/{$this->driveId}");
        } catch (SharePointException) {
            return false;
        }

        return true;
    }

    /**
     * List the immediate children of the configured drive/folder.
     *
     * @return array<int, array<string, mixed>> raw Graph driveItem resources
     */
    public function listChildren(): array
    {
        $path = $this->folderPath === ''
            ? "/drives/{$this->driveId}/root/children"
            : "/drives/{$this->driveId}/root:/{$this->folderPath}:/children";

        $items = $this->request('GET', $path)->json('value');

        if (! is_array($items)) {
            throw new SharePointException('Microsoft Graph returned an unexpected response listing files.');
        }

        return $items;
    }

    /**
     * Fetch one item's metadata (name, size, lastModifiedDateTime, etc.).
     *
     * @return array<string, mixed> raw Graph driveItem resource
     */
    public function getItemMetadata(string $itemId): array
    {
        $item = $this->request('GET', "/drives/{$this->driveId}/items/{$itemId}")->json();

        if (! is_array($item)) {
            throw new SharePointException('Microsoft Graph returned an unexpected response for item metadata.');
        }

        return $item;
    }

    /**
     * Download one item's raw file content.
     */
    public function downloadContent(string $itemId): string
    {
        return $this->request('GET', "/drives/{$this->driveId}/items/{$itemId}/content", self::DOWNLOAD_TIMEOUT)->body();
    }

    private function request(string $method, string $path, int $timeout = self::REQUEST_TIMEOUT): Response
    {
        try {
            return Http::baseUrl(self::BASE_URL)
                ->withToken($this->getAccessToken())
                ->timeout($timeout)
                ->retry(self::RETRIES, self::RETRY_DELAY_MS)
                ->send($method, $path);
        } catch (ConnectionException|RequestException $e) {
            throw new SharePointException("Microsoft Graph request failed: {$method} {$path}", previous: $e);
        }
    }

    /**
     * Fetch (and memoize) an app-only access token via the OAuth2 client
     * credentials flow.
     */
    private function getAccessToken(): string
    {
        if ($this->cachedToken !== null && time() < $this->tokenExpiresAt) {
            return $this->cachedToken;
        }

        try {
            $response = Http::asForm()
                ->timeout(self::REQUEST_TIMEOUT)
                ->post("https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token", [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'scope' => 'https://graph.microsoft.com/.default',
                    'grant_type' => 'client_credentials',
                ]);
        } catch (ConnectionException $e) {
            throw new SharePointException('Could not reach the Microsoft identity platform.', previous: $e);
        }

        if ($response->failed()) {
            throw new SharePointException("Microsoft Graph authentication failed with status {$response->status()}.");
        }

        $token = $response->json('access_token');
        $expiresIn = $response->json('expires_in');

        if (! is_string($token) || ! is_int($expiresIn)) {
            throw new SharePointException('The Microsoft identity platform returned an unexpected token response.');
        }

        $this->cachedToken = $token;
        // 30-second buffer so a token already in use doesn't expire mid-request.
        $this->tokenExpiresAt = time() + $expiresIn - 30;

        return $token;
    }
}
