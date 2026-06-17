<?php

namespace App\Services;

use App\Models\User;
use App\Models\Certificate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MumbleService
{
    /**
     * Get OpenSSL configuration parameters (primarily for Windows compatibility).
     *
     * @return array
     */
    private static function getOpenSSLConfig()
    {
        $config = [];

        // Check if a specific path is defined in env
        if ($envPath = env('OPENSSL_CONF')) {
            $config['config'] = $envPath;
            return $config;
        }

        // Test if default works (e.g., on Linux/Alpine)
        $test = @openssl_pkey_new();
        if ($test !== false) {
            return $config;
        }

        // Windows / Laragon compatibility fallbacks
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $phpDir = dirname(PHP_BINARY);
            $possiblePaths = [
                $phpDir . '\extras\ssl\openssl.cnf',
                $phpDir . '\extras\openssl.cnf',
                'C:\laragon\bin\php\php-8.3\extras\ssl\openssl.cnf',
                'C:\laragon\bin\php\php-8.3\extras\openssl.cnf',
                'C:\laragon\bin\php\php-8.2\extras\ssl\openssl.cnf',
                'C:\laragon\bin\php\php-8.1\extras\ssl\openssl.cnf',
            ];

            foreach ($possiblePaths as $path) {
                if (file_exists($path)) {
                    $config['config'] = $path;
                    break;
                }
            }
        }

        return $config;
    }

    /**
     * Generate PKCS12 (.p12) user certificate for Mumble and register in Murmur DB.
     *
     * @param User $user
     * @return bool
     */
    public static function generateAndRegister(User $user)
    {
        try {
            $sslConfig = self::getOpenSSLConfig();

            // 1. Generate new private key
            $privateKey = openssl_pkey_new(array_merge([
                "private_key_bits" => 2048,
                "private_key_type" => OPENSSL_KEYTYPE_RSA,
            ], $sslConfig));

            if (!$privateKey) {
                throw new \Exception("Failed to generate private key: " . openssl_error_string());
            }

            // 2. Create CSR (Certificate Signing Request)
            $dn = [
                "commonName" => $user->name,
                "emailAddress" => $user->email,
            ];
            $csr = openssl_csr_new($dn, $privateKey, array_merge(['digest_alg' => 'sha256'], $sslConfig));

            if (!$csr) {
                throw new \Exception("Failed to generate CSR: " . openssl_error_string());
            }

            // 3. Self-sign certificate (valid for 10 years)
            $x509 = openssl_csr_sign($csr, null, $privateKey, 3650, array_merge(['digest_alg' => 'sha256'], $sslConfig));

            if (!$x509) {
                throw new \Exception("Failed to sign certificate: " . openssl_error_string());
            }

            // 4. Export to PEM format to extract the DER fingerprint
            openssl_x509_export($x509, $pemCert);

            // Export private key to PEM
            $privateKeyPem = '';
            openssl_pkey_export($privateKey, $privateKeyPem, null, $sslConfig);

            // 5. Calculate SHA-1 fingerprint of the DER certificate
            $pemBody = str_replace('-----BEGIN CERTIFICATE-----', '', $pemCert);
            $pemBody = str_replace('-----END CERTIFICATE-----', '', $pemBody);
            $pemBody = str_replace(["\r", "\n", ' '], '', $pemBody);
            $derCert = base64_decode($pemBody);
            $certHash = sha1($derCert);

            // 6. Export to PKCS12 (.p12) in public/certificates/
            $fileName = 'cert_' . $user->id . '_' . time() . '.p12';
            $dirPath = public_path('certificates');
            if (!file_exists($dirPath)) {
                mkdir($dirPath, 0755, true);
            }
            $filePath = $dirPath . '/' . $fileName;

            // Use the compatible helper method to export PKCS12 file
            $exportSuccess = self::exportPKCS12($pemCert, $privateKeyPem, $filePath, $user->name);

            if (!$exportSuccess) {
                throw new \Exception("Failed to export PKCS12 certificate: " . openssl_error_string());
            }

            $relativePath = 'certificates/' . $fileName;

            // 8. Save/Update Laravel Certificate model
            Certificate::updateOrCreate(
                ['user_id' => $user->id],
                ['certificate_path' => $relativePath]
            );

            // 9. Sync user to Murmur database
            self::registerUserToMumble($user, $certHash);

            return true;
        } catch (\Throwable $e) {
            Log::error("MumbleService Error for user ID {$user->id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Export a certificate and private key to PKCS12 (.p12) format.
     * Generates a compatible output on both OpenSSL 1.1.1 and 3.0+.
     *
     * @param string $pemCert
     * @param string $privateKeyPem
     * @param string $filePath
     * @param string $friendlyName
     * @return bool
     */
    private static function exportPKCS12(string $pemCert, string $privateKeyPem, string $filePath, string $friendlyName)
    {
        $tempKey = tempnam(sys_get_temp_dir(), 'key');
        $tempCert = tempnam(sys_get_temp_dir(), 'cert');

        file_put_contents($tempKey, $privateKeyPem);
        file_put_contents($tempCert, $pemCert);

        $success = false;

        // Try 1: Unencrypted PKCS12 (NONE) - compatible with OpenSSL 1.1.1 & 3.0, no legacy provider needed
        $cmd = sprintf(
            'openssl pkcs12 -export -in %s -inkey %s -out %s -name %s -passout pass:"" -keypbe NONE -certpbe NONE -nomac 2>&1',
            escapeshellarg($tempCert),
            escapeshellarg($tempKey),
            escapeshellarg($filePath),
            escapeshellarg($friendlyName)
        );
        @shell_exec($cmd);

        if (file_exists($filePath) && filesize($filePath) > 0) {
            $success = true;
        }

        // Try 2: Fallback to explicit legacy options (if Try 1 failed)
        if (!$success) {
            $cmdLegacy = sprintf(
                'openssl pkcs12 -export -in %s -inkey %s -out %s -name %s -passout pass:"" -certpbe PBE-SHA1-3DES -keypbe PBE-SHA1-3DES -macalg sha1 2>&1',
                escapeshellarg($tempCert),
                escapeshellarg($tempKey),
                escapeshellarg($filePath),
                escapeshellarg($friendlyName)
            );
            @shell_exec($cmdLegacy);

            if (file_exists($filePath) && filesize($filePath) > 0) {
                $success = true;
            }
        }

        @unlink($tempKey);
        @unlink($tempCert);

        // Try 3: Fallback to PHP native openssl_pkcs12_export (if CLI tools failed/disabled)
        if (!$success) {
            Log::warning("OpenSSL CLI export failed. Falling back to PHP native openssl_pkcs12_export.");
            $privateKey = openssl_pkey_get_private($privateKeyPem);
            $x509 = openssl_x509_read($pemCert);
            
            $p12Data = '';
            $success = openssl_pkcs12_export($x509, $p12Data, $privateKey, '', [
                'friendly_name' => $friendlyName
            ]);
            
            if ($success) {
                file_put_contents($filePath, $p12Data);
            }
        }

        if ($success) {
            @chmod($filePath, 0644);
        }

        return $success;
    }

    /**
     * Register or update user details in Murmur's users table.
     *
     * @param User $user
     * @param string $certHash
     * @return bool
     */
    public static function registerUserToMumble(User $user, string $certHash)
    {
        try {
            $connection = DB::connection('mumble');
            $connection->getPdo();
        } catch (\Throwable $e) {
            Log::warning("Could not connect to Mumble database to register user: " . $e->getMessage());
            return false;
        }

        try {
            // Find active virtual servers in Murmur DB
            $servers = $connection->table('servers')->pluck('server_id');
            if ($servers->isEmpty()) {
                $servers = collect([1]); // Default fallback
            }

            foreach ($servers as $serverId) {
                $existing = $connection->table('users')
                    ->where('server_id', $serverId)
                    ->where('name', $user->name)
                    ->first();

                if ($existing) {
                    $connection->table('users')
                        ->where('server_id', $serverId)
                        ->where('name', $user->name)
                        ->update([
                            'email' => $user->email,
                            'hash' => $certHash,
                            'last_active' => now(),
                        ]);
                } else {
                    $connection->table('users')->insert([
                        'server_id' => $serverId,
                        'name' => $user->name,
                        'email' => $user->email,
                        'hash' => $certHash,
                        'pw' => '',
                        'last_active' => now(),
                    ]);
                }
            }
            return true;
        } catch (\Throwable $e) {
            Log::error("Failed to insert/update user in Murmur database: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete user registration from all virtual servers in the Murmur database.
     *
     * @param User $user
     * @return bool
     */
    public static function deregisterUserFromMumble(User $user)
    {
        try {
            $connection = DB::connection('mumble');
            $connection->getPdo();
        } catch (\Throwable $e) {
            Log::warning("Could not connect to Mumble database to deregister user: " . $e->getMessage());
            return false;
        }

        try {
            $connection->table('users')
                ->where('name', $user->name)
                ->delete();
            return true;
        } catch (\Throwable $e) {
            Log::error("Failed to delete user from Murmur database: " . $e->getMessage());
            return false;
        }
    }
}
