<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\SharePointException;
use App\ValueObjects\ConnectionStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Low-level wrapper around Microsoft Graph's v1.0 API. The only class
 * that knows Graph's authentication flow, URL shapes, and JSON format -
 * SharePointExcelService builds on this without knowing any of that.
 *
 * Authenticates via the OAuth2 client credentials (app-only) flow using
 * the tenant/client ID and secret from config/sharepoint.php. The access
 * token, resolved Site ID, and resolved Drive ID are all memoized in
 * memory for this instance's lifetime only - nothing is persisted to a
 * cache store, and the client secret is never logged.
 *
 * Every value this class needs - tenant/client ID, client secret, site
 * URL, document library name, Excel folder - comes from
 * config/sharepoint.php. The Site ID and Drive ID are never configured
 * directly; they're resolved dynamically from the site URL and document
 * library name on first use, so setting this up is purely a matter of
 * filling in .env - see docs/sharepoint-setup.md.
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

    private ?string $cachedSiteId = null;

    private ?string $cachedDriveId = null;

    private readonly string $tenantId;

    private readonly string $clientId;

    private readonly string $clientSecret;

    private readonly string $siteUrl;

    private readonly string $documentLibrary;

    private readonly string $excelFolder;

    public function __construct()
    {
        $this->tenantId = (string) config('sharepoint.tenant_id');
        $this->clientId = (string) config('sharepoint.client_id');
        $this->clientSecret = (string) config('sharepoint.client_secret');
        $this->siteUrl = trim((string) config('sharepoint.site_url'));
        $this->documentLibrary = (string) config('sharepoint.document_library');
        $this->excelFolder = trim((string) config('sharepoint.excel_folder'), '/');
    }

    /**
     * Whether SharePoint is configured and reachable. Returns
     * NotConfigured (without attempting any HTTP call) when
     * SHAREPOINT_SITE_URL is empty - never throws.
     */
    public function healthCheck(): ConnectionStatus
    {
        if ($this->siteUrl === '') {
            return ConnectionStatus::NotConfigured;
        }

        try {
            $this->resolveDriveId();
        } catch (SharePointException) {
            return ConnectionStatus::Disconnected;
        }

        return ConnectionStatus::Connected;
    }

    /**
     * Resolve the configured site URL to a Graph Site ID.
     */
    public function resolveSiteId(): string
    {
        if ($this->cachedSiteId !== null) {
            return $this->cachedSiteId;
        }

        if ($this->siteUrl === '') {
            throw new SharePointException('SharePoint is not configured (SHAREPOINT_SITE_URL is empty).');
        }

        $host = parse_url($this->siteUrl, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            throw new SharePointException("The configured SharePoint site URL (\"{$this->siteUrl}\") is not a valid URL.");
        }

        $path = rtrim((string) (parse_url($this->siteUrl, PHP_URL_PATH) ?? ''), '/');
        $graphPath = $path === '' ? "/sites/{$host}" : "/sites/{$host}:{$path}";

        $site = $this->request('GET', $graphPath)->json();

        if (! is_array($site) || ! isset($site['id']) || ! is_string($site['id'])) {
            throw new SharePointException('Microsoft Graph returned an unexpected response resolving the SharePoint site.');
        }

        return $this->cachedSiteId = $site['id'];
    }

    /**
     * List every document library (drive) on the configured site, for
     * diagnostics - not filtered to the configured document library.
     *
     * @return array<int, array{id: string, name: string, webUrl: string}>
     */
    public function listDocumentLibraries(): array
    {
        $siteId = $this->resolveSiteId();
        $drives = $this->request('GET', "/sites/{$siteId}/drives")->json('value');

        if (! is_array($drives)) {
            throw new SharePointException('Microsoft Graph returned an unexpected response listing document libraries.');
        }

        return array_map(
            /** @param array<string, mixed> $drive */
            static fn (array $drive): array => [
                'id' => (string) ($drive['id'] ?? ''),
                'name' => (string) ($drive['name'] ?? ''),
                'webUrl' => (string) ($drive['webUrl'] ?? ''),
            ],
            $drives,
        );
    }

    /**
     * List the immediate children of the configured document library's
     * Excel folder.
     *
     * @return array<int, array<string, mixed>> raw Graph driveItem resources
     */
    public function listChildren(): array
    {
        $driveId = $this->resolveDriveId();

        $path = $this->excelFolder === ''
            ? "/drives/{$driveId}/root/children"
            : "/drives/{$driveId}/root:/{$this->excelFolder}:/children";

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
        $driveId = $this->resolveDriveId();
        $item = $this->request('GET', "/drives/{$driveId}/items/{$itemId}")->json();

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
        $driveId = $this->resolveDriveId();

        return $this->request('GET', "/drives/{$driveId}/items/{$itemId}/content", self::DOWNLOAD_TIMEOUT)->body();
    }

    /**
     * Resolve the configured document library name to a Graph Drive ID.
     */
    public function resolveDriveId(): string
    {
        if ($this->cachedDriveId !== null) {
            return $this->cachedDriveId;
        }

        $libraries = $this->listDocumentLibraries();

        foreach ($libraries as $drive) {
            if ($drive['name'] === $this->documentLibrary) {
                return $this->cachedDriveId = $drive['id'];
            }
        }

        // Graph's default document library is internally named "Documents"
        // even when SharePoint's UI (and the config default) calls it
        // "Shared Documents" - fall back to matching by webUrl so that
        // common default value still resolves correctly.
        $encodedLibrary = rawurlencode($this->documentLibrary);

        foreach ($libraries as $drive) {
            if (str_ends_with($drive['webUrl'], $encodedLibrary)) {
                return $this->cachedDriveId = $drive['id'];
            }
        }

        throw new SharePointException("No document library named \"{$this->documentLibrary}\" was found on the configured SharePoint site.");
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
