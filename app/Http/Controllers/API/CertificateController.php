<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class CertificateController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'certificate' => [
                'required',
                'file',
                'max:2048',
                function ($attribute, $value, $fail) {
                    if (!$value instanceof \Illuminate\Http\UploadedFile) {
                        return;
                    }

                    $allowedExtensions = ['p12', 'crt', 'pem', 'cer', 'pfx', 'der'];
                    $extension = strtolower($value->getClientOriginalExtension());
                    if (!in_array($extension, $allowedExtensions, true)) {
                        $fail('The certificate must have a permitted file extension.');
                        return;
                    }

                    $content = @file_get_contents($value->getRealPath());
                    if ($content === false || strlen($content) === 0) {
                        $fail('The certificate file is empty or unreadable.');
                        return;
                    }

                    // Disallow PHP scripts and HTML tags
                    if (preg_match('/<\?(?:php|=)/i', $content) || stripos($content, '<script') !== false) {
                        $fail('The certificate contains invalid executable content.');
                        return;
                    }

                    // For text-based certificates, verify certificate or key envelope
                    if (in_array($extension, ['pem', 'crt', 'cer'], true)) {
                        $isPemStructure = preg_match('/-----BEGIN [A-Z0-9\s]+-----/', $content);
                        $isDerStructure = (ord($content[0]) === 0x30);
                        if (!$isPemStructure && !$isDerStructure) {
                            $fail('The certificate does not contain a valid certificate or key structure.');
                            return;
                        }
                    }
                },
            ],
        ]);

        $file = $request->file('certificate');
        $extension = strtolower($file->getClientOriginalExtension());
        $fileName = Str::uuid() . '.' . $extension;
        $directory = public_path('certificates');
        $newFilePath = $directory . DIRECTORY_SEPARATOR . $fileName;
        $newCertificatePath = 'certificates/' . $fileName;

        $certificate = Auth::user()->certificate;
        $oldCertificatePath = $certificate ? $certificate->certificate_path : null;

        // Move to destination before updating database record
        $file->move($directory, $fileName);

        try {
            if ($certificate) {
                if (!$certificate->update([
                    'certificate_path' => $newCertificatePath,
                ])) {
                    throw new \RuntimeException('Failed to update certificate record.');
                }
            } else {
                Certificate::create([
                    'user_id' => Auth::id(),
                    'certificate_path' => $newCertificatePath,
                ]);
            }
        } catch (\Throwable $exception) {
            if (is_file($newFilePath)) {
                @unlink($newFilePath);
            }

            throw $exception;
        }

        if ($oldCertificatePath) {
            $normalizedOldPath = $this->normalizeCertificatePath($oldCertificatePath);
            $oldFilePath = public_path($normalizedOldPath);

            // Compare against all other records using the same normalized path
            $isReferencedByAnotherCertificate = Certificate::where('id', '!=', $certificate->id)
                ->whereNotNull('certificate_path')
                ->get(['id', 'certificate_path'])
                ->contains(function ($otherCert) use ($normalizedOldPath) {
                    return $this->normalizeCertificatePath($otherCert->certificate_path) === $normalizedOldPath;
                });

            if (!$isReferencedByAnotherCertificate && is_file($oldFilePath)) {
                @unlink($oldFilePath);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Sertifikat berhasil diunggah!',
        ], 200);
    }

    private function normalizeCertificatePath(?string $path): string
    {
        if (empty($path)) {
            return '';
        }

        $path = str_replace('\\', '/', ltrim($path, '/\\'));

        return strpos($path, 'certificates/') === 0 ? $path : 'certificates/' . $path;
    }
}
