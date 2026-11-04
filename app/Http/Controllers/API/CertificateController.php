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
                'mimes:p12,crt,pem,cer,pfx,der',
                'max:2048',
                function ($attribute, $value, $fail) {
                    $allowedExtensions = ['p12', 'crt', 'pem', 'cer', 'pfx', 'der'];
                    if ($value instanceof \Illuminate\Http\UploadedFile
                        && !in_array(strtolower($value->getClientOriginalExtension()), $allowedExtensions, true)) {
                        $fail('The certificate must have a permitted file extension.');
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

        // Move to a new path before changing the record so the old file remains intact on failure.
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
                unlink($newFilePath);
            }

            throw $exception;
        }

        if ($oldCertificatePath) {
            $normalizedOldPath = $this->normalizeCertificatePath($oldCertificatePath);
            $oldFilePath = public_path($normalizedOldPath);
            $legacyOldPath = substr($normalizedOldPath, strlen('certificates/'));
            $pathAliases = array_unique([$oldCertificatePath, $normalizedOldPath, $legacyOldPath]);

            $isReferencedByAnotherCertificate = Certificate::where('id', '!=', $certificate->id)
                ->whereIn('certificate_path', $pathAliases)
                ->exists();

            if (!$isReferencedByAnotherCertificate && is_file($oldFilePath)) {
                unlink($oldFilePath);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Sertifikat berhasil diunggah!',
        ], 200);
    }

    private function normalizeCertificatePath(string $path): string
    {
        $path = str_replace('\\', '/', ltrim($path, '/\\'));

        return strpos($path, 'certificates/') === 0 ? $path : 'certificates/' . $path;
    }
}
