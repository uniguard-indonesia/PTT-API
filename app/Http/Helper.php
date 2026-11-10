<?php
namespace App\Http;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Helper
{
    private static ?array $validTimezoneMap = null;

    private static function getValidTimezoneMap(): array
    {
        if (self::$validTimezoneMap === null) {
            self::$validTimezoneMap = array_flip(
                \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC)
            );
        }

        return self::$validTimezoneMap;
    }

    public static function isValidTimezone(?string $timezoneCode): bool
    {
        if (!is_string($timezoneCode) || $timezoneCode === '' || $timezoneCode !== trim($timezoneCode)) {
            return false;
        }

        $map = self::getValidTimezoneMap();
        if (!isset($map[$timezoneCode])) {
            return false;
        }

        try {
            new \DateTimeZone($timezoneCode);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function sanitizeTimezone(?string $timezoneCode, ?string $fallback = null): string
    {
        $candidate = is_string($timezoneCode) ? trim($timezoneCode) : '';
        if (self::isValidTimezone($candidate)) {
            return $candidate;
        }

        $fallbackSource = $fallback ?? config('app.timezone', 'UTC');
        $cleanFallback = is_string($fallbackSource) ? trim($fallbackSource) : '';
        if (self::isValidTimezone($cleanFallback)) {
            return $cleanFallback;
        }

        return 'UTC';
    }

    private static function resolveUniqueTimezoneName(string $preferredName, int $excludeId, string $fallbackName): string
    {
        $trimmed = trim($preferredName);
        $candidate = $trimmed !== '' ? $trimmed : $fallbackName;

        if (!DB::table('timezones')->where('name', $candidate)->where('id', '!=', $excludeId)->exists()) {
            return $candidate;
        }

        if (
            $fallbackName !== $candidate &&
            !DB::table('timezones')->where('name', $fallbackName)->where('id', '!=', $excludeId)->exists()
        ) {
            return $fallbackName;
        }

        $suffix = 1;
        do {
            $disambiguated = $candidate . ' (' . $excludeId . ($suffix > 1 ? '-' . $suffix : '') . ')';
            $suffix++;
        } while (DB::table('timezones')->where('name', $disambiguated)->where('id', '!=', $excludeId)->exists());

        return $disambiguated;
    }

    public static function sanitizeStoredTimezones(): void
    {
        if (!Schema::hasTable('timezones')) {
            return;
        }

        DB::transaction(function () {
            $rows = DB::table('timezones')->orderBy('id')->get();
            if ($rows->isEmpty()) {
                return;
            }

            $fallbackCode = self::sanitizeTimezone(config('app.timezone', 'UTC'), 'UTC');
            $hasCompanyTimezone = Schema::hasTable('companies') && Schema::hasColumn('companies', 'timezone_id');

            // Ensure a clean canonical fallback timezone row exists before merging invalid rows
            $fallbackRow = $rows->first(fn ($r) => $r->code === $fallbackCode)
                ?? $rows->first(fn ($r) => trim((string) $r->code) === $fallbackCode);

            if ($fallbackRow) {
                $updates = [];
                if ($fallbackRow->code !== $fallbackCode) {
                    $updates['code'] = $fallbackCode;
                }
                $safeName = self::resolveUniqueTimezoneName(
                    trim((string) $fallbackRow->name) !== '' ? trim((string) $fallbackRow->name) : $fallbackCode,
                    (int) $fallbackRow->id,
                    $fallbackCode
                );
                if ($fallbackRow->name !== $safeName) {
                    $updates['name'] = $safeName;
                }
                if (!empty($updates)) {
                    $updates['updated_at'] = now();
                    DB::table('timezones')->where('id', $fallbackRow->id)->update($updates);
                }
            } else {
                $hasInvalidRows = $rows->contains(fn ($r) => !self::isValidTimezone(trim((string) $r->code)));
                if ($hasInvalidRows) {
                    $safeName = self::resolveUniqueTimezoneName($fallbackCode, 0, $fallbackCode);
                    DB::table('timezones')->insert([
                        'code' => $fallbackCode,
                        'name' => $safeName,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // Re-read after ensuring canonical fallback row exists
            $rows = DB::table('timezones')->orderBy('id')->get();

            foreach ($rows as $row) {
                $current = DB::table('timezones')->where('id', $row->id)->first();
                if (!$current) {
                    continue;
                }

                $trimmedCode = trim((string) $current->code);
                $trimmedName = trim((string) $current->name);
                $isValidAfterTrim = self::isValidTimezone($trimmedCode);
                $targetCode = $isValidAfterTrim ? $trimmedCode : $fallbackCode;

                $canonical = DB::table('timezones')
                    ->where('code', $targetCode)
                    ->where('id', '!=', $current->id)
                    ->orderBy('id')
                    ->first();

                if ($canonical) {
                    if ($hasCompanyTimezone) {
                        DB::table('companies')
                            ->where('timezone_id', $current->id)
                            ->update(['timezone_id' => $canonical->id]);
                    }
                    DB::table('timezones')->where('id', $current->id)->delete();
                    continue;
                }

                $updates = [];
                if ($current->code !== $targetCode) {
                    $updates['code'] = $targetCode;
                }

                $desiredName = $isValidAfterTrim
                    ? ($trimmedName !== '' ? $trimmedName : $targetCode)
                    : $targetCode;
                $safeName = self::resolveUniqueTimezoneName($desiredName, (int) $current->id, $targetCode);
                if ($current->name !== $safeName) {
                    $updates['name'] = $safeName;
                }

                if (!empty($updates)) {
                    $updates['updated_at'] = now();
                    DB::table('timezones')->where('id', $current->id)->update($updates);
                }
            }
        });
    }

    private static function btnIcon($status)
    {
        switch ($status) {
            case 'edit':
                $icon = 'ti-pencil';
                break;
            case 'delete':
                $icon = 'ti-trash';
                break;
            case 'reset':
                $icon = 'ti-key';
                break;
            case 'regenerate':
                $icon = 'ti-reload';
                break;
        }
        return $icon;
    }
    private static function btnColor($btn)
    {
        switch ($btn) {
            case 'edit':
                $color = 'btn-success';
                break;
            case 'delete':
                $color = 'btn-danger';
                break;
            case 'reset':
                $color = 'btn-info';
                break;
            case 'regenerate':
                $color = 'btn-warning';
                break;
        }
        return $color;
    }
    private static function btnAction($btn, $data)
    {
        switch ($btn) {
            case 'edit':
                $action = 'edit('.$data->id.')';
                break;
            case 'delete':
                $action = 'destroy('.$data->id.')';
                break;
            case 'reset':
                $action = 'resetPassword('.$data->id.')';
                break;
            case 'regenerate':
                $action = 'regenerateCert('.$data->id.')';
                break;
        }
        return $action;
    }
    public static function actionButtons($data, $buttons)
    {
        $btn = '';
        foreach ($buttons as $key => $val) {
            $btn .= '<button class="btn btn-sm '.self::btnColor($val).'" onclick="'.self::btnAction($val, $data).'" type="button"><i class="'.self::btnIcon($val).'"></i></button>';
        }
        return '<div class="btn-group">'.$btn.'</div>';
    }
}
