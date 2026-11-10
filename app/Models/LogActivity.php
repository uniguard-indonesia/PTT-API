<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LogActivity extends Model
{
    public const ALLOWED_AUDIO_EXTENSIONS = ['mp3', 'wav', 'ogg', 'm4a', 'aac'];

    protected $table = 'log_activities';
    protected $guarded = ['id'];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Extract clean activity text and validated coordinates from a log activity message.
     *
     * @param  string|null  $activity
     * @return array{activity: string, latitude: float|null, longitude: float|null}
     */
    public static function extractLocationData(?string $activity): array
    {
        if (!is_string($activity)) {
            return [
                'activity' => '',
                'latitude' => null,
                'longitude' => null,
            ];
        }

        if (preg_match(
            '/\A\s*Updating Location\s*\(\s*([+-]?(?:\d+(?:\.\d+)?|\.\d+)(?:[eE][+-]?\d+)?)\s*,\s*([+-]?(?:\d+(?:\.\d+)?|\.\d+)(?:[eE][+-]?\d+)?)\s*\)\s*\z/',
            $activity,
            $matches
        )) {
            $latitude = (float) $matches[1];
            $longitude = (float) $matches[2];

            if (
                is_finite($latitude) &&
                is_finite($longitude) &&
                $latitude >= -90.0 &&
                $latitude <= 90.0 &&
                $longitude >= -180.0 &&
                $longitude <= 180.0
            ) {
                return [
                    'activity' => 'Updating Location',
                    'latitude' => $latitude == 0.0 ? 0.0 : $latitude,
                    'longitude' => $longitude == 0.0 ? 0.0 : $longitude,
                ];
            }
        }

        return [
            'activity' => $activity,
            'latitude' => null,
            'longitude' => null,
        ];
    }

    public static function storageDirectory(): string
    {
        return storage_path('app/audio');
    }

    public static function normalizeAttachmentPath(?string $path): ?string
    {
        if (!is_string($path)) {
            return null;
        }

        $path = trim($path);
        if ($path === '' || strpos($path, "\0") !== false) {
            return null;
        }

        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');

        if ($path === '') {
            return null;
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        if (strpos($path, 'audio/') !== 0) {
            $path = 'audio/' . $path;
        }

        return $path;
    }

    public static function resolveAttachmentFilePath(?string $path): ?string
    {
        $normalized = self::normalizeAttachmentPath($path);
        if ($normalized === null) {
            return null;
        }

        $subpath = substr($normalized, strlen('audio/'));
        $extension = strtolower(pathinfo($subpath, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_AUDIO_EXTENSIONS, true)) {
            return null;
        }

        $baseDirs = [
            self::storageDirectory(),
            public_path('audio'),
        ];

        foreach ($baseDirs as $baseDir) {
            $realBase = realpath($baseDir);
            if ($realBase === false || !is_dir($realBase)) {
                continue;
            }

            $candidatePath = $realBase . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $subpath);
            $realCandidate = realpath($candidatePath);

            if (
                $realCandidate !== false &&
                is_file($realCandidate) &&
                strpos($realCandidate, $realBase . DIRECTORY_SEPARATOR) === 0
            ) {
                return $realCandidate;
            }
        }

        return null;
    }

    public static function mimeTypeForExtension(string $extension): string
    {
        switch (strtolower($extension)) {
            case 'mp3':
                return 'audio/mpeg';
            case 'wav':
                return 'audio/wav';
            case 'ogg':
                return 'audio/ogg';
            case 'm4a':
                return 'audio/mp4';
            case 'aac':
                return 'audio/aac';
            default:
                return 'application/octet-stream';
        }
    }
}
