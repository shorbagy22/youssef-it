<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\ExcelFileProvider;
use App\Exceptions\SharePointException;
use App\Services\MicrosoftGraphClient;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Diagnostic command for setting up SharePoint: requests a token, decodes
 * its claims, calls the site endpoint, and prints full unredacted detail
 * on every response - status, headers, complete body. Deliberately makes
 * its own raw HTTP calls rather than going through MicrosoftGraphClient
 * for the token/site steps: that class's request() method exists to
 * retry and collapse failures into a single SharePointException message
 * (right for production use, wrong for diagnosing exactly what Graph
 * sent back). MicrosoftGraphClient itself, and the auth flow it
 * implements, are untouched by this command.
 *
 * Once the site call succeeds, it falls through to the same
 * MicrosoftGraphClient/ExcelFileProvider calls as before to list document
 * libraries and Excel files.
 */
final class TestSharePointConnection extends Command
{
    protected $signature = 'sharepoint:test';

    protected $description = 'Test the SharePoint/Microsoft Graph connection without syncing anything';

    public function __construct(
        private readonly ExcelFileProvider $excelFiles,
        private readonly MicrosoftGraphClient $graphClient,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenantId = (string) config('sharepoint.tenant_id');
        $clientId = (string) config('sharepoint.client_id');
        $clientSecret = (string) config('sharepoint.client_secret');
        $siteUrl = trim((string) config('sharepoint.site_url'));

        if ($siteUrl === '') {
            $this->info('SharePoint is not configured.');

            return self::SUCCESS;
        }

        // --- Step 1: request an access token, full diagnostics --------
        $tokenUrl = "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token";

        $tokenResponse = Http::asForm()->post($tokenUrl, [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ]);

        $this->printResponseDiagnostics('Token request', 'POST', $tokenUrl, $tokenResponse);

        if ($tokenResponse->failed()) {
            $this->error('Token request failed - stopping before any Graph call.');

            return self::FAILURE;
        }

        $accessToken = $tokenResponse->json('access_token');

        if (! is_string($accessToken)) {
            $this->error('Token response did not include a string access_token.');

            return self::FAILURE;
        }

        // --- Step 2: decode the JWT, no signature verification ---------
        $this->printJwtClaims($accessToken);

        // --- Step 3: call the site endpoint, full diagnostics ----------
        $host = parse_url($siteUrl, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            $this->error("The configured SharePoint site URL (\"{$siteUrl}\") is not a valid URL.");

            return self::FAILURE;
        }

        $path = rtrim((string) (parse_url($siteUrl, PHP_URL_PATH) ?? ''), '/');
        $graphPath = $path === '' ? "/sites/{$host}" : "/sites/{$host}:{$path}";
        $siteEndpoint = 'https://graph.microsoft.com/v1.0'.$graphPath;

        $siteResponse = Http::withToken($accessToken)->get($siteEndpoint);

        $this->printResponseDiagnostics('Site request', 'GET', $siteEndpoint, $siteResponse);

        if ($siteResponse->failed()) {
            return self::FAILURE;
        }

        // Site resolution succeeded - continue with the existing checks.
        $this->info('Authenticated successfully.');

        try {
            $this->info('Available document libraries:');
            foreach ($this->graphClient->listDocumentLibraries() as $library) {
                $this->line("  - {$library['name']}");
            }

            $excelFolder = (string) config('sharepoint.excel_folder');
            $this->info("Excel files in \"{$excelFolder}\":");
            foreach ($this->excelFiles->listExcelFiles() as $file) {
                $this->line("  - {$file->name}");
            }
        } catch (SharePointException $e) {
            $this->error("SharePoint check failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Print HTTP status, request URL, every response header, and the
     * complete, unredacted response body - no truncation, unlike
     * RequestException's own message formatting.
     */
    private function printResponseDiagnostics(string $label, string $method, string $url, Response $response): void
    {
        $this->newLine();
        $this->info("=== {$label}: {$method} {$url} ===");
        $this->line('HTTP status: '.$response->status());

        $this->line('Response headers:');
        foreach ($response->headers() as $name => $values) {
            $this->line("  {$name}: ".implode(', ', $values));
        }

        $this->line('Response body:');
        $this->line($response->body());
    }

    /**
     * Decode and print the access token's claims. Signature is
     * deliberately NOT verified - this is a read-only diagnostic, not an
     * authentication step, and the token was already obtained directly
     * from Microsoft over a verified TLS connection above.
     */
    private function printJwtClaims(string $accessToken): void
    {
        $this->newLine();
        $this->info('=== Access token claims (decoded only, signature NOT verified) ===');

        $claims = $this->decodeJwtPayload($accessToken);

        if ($claims === null) {
            $this->error('Could not decode the access token as a JWT.');

            return;
        }

        $this->line('aud (audience): '.$this->formatClaim($claims['aud'] ?? null));
        $this->line('iss (issuer): '.$this->formatClaim($claims['iss'] ?? null));
        $this->line('tid (tenant): '.$this->formatClaim($claims['tid'] ?? null));
        $this->line('appid (application id): '.$this->formatClaim($claims['appid'] ?? $claims['azp'] ?? null));
        $this->line('roles: '.$this->formatClaim($claims['roles'] ?? null));
        $this->line('scp (scopes): '.$this->formatClaim($claims['scp'] ?? null));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJwtPayload(string $jwt): ?array
    {
        $segments = explode('.', $jwt);

        if (count($segments) !== 3) {
            return null;
        }

        $payload = strtr($segments[1], '-_', '+/');
        $payload = str_pad($payload, strlen($payload) + (4 - strlen($payload) % 4) % 4, '=');

        $decoded = base64_decode($payload, true);

        if ($decoded === false) {
            return null;
        }

        $claims = json_decode($decoded, true);

        return is_array($claims) ? $claims : null;
    }

    private function formatClaim(mixed $value): string
    {
        if ($value === null) {
            return '(not present)';
        }

        if (is_array($value)) {
            return $value === [] ? '(empty array)' : implode(', ', $value);
        }

        return (string) $value;
    }
}
