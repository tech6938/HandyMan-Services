<?php

namespace Modules\AddonModule\Traits;

trait AddonHelper
{
    public function get_addons(): array
    {
        $dir = 'Modules';
        $directories = self::getDirectories($dir);
        $addons = [];
        foreach ($directories as $directory) {
            $subDirectories = self::getDirectories('Modules/' . $directory);
            if (in_array('Addon', $subDirectories)) {
                $addons[] = 'Modules/' . $directory;
            }
        }

        $array = [];
        foreach ($addons as $item) {
            $fullData = include(base_path($item . '/Addon/info.php'));
            $array[] = [
                'addon_name' => $fullData['name'],
                'software_id' => $fullData['software_id'],
                'is_published' => $fullData['is_published'],
            ];
        }

        return $array;
    }

    public function get_addon_admin_routes(): array
    {
        $dir = 'Modules';
        $directories = self::getDirectories($dir);
        $addons = [];
        foreach ($directories as $directory) {
            $subDirectories = self::getDirectories('Modules/' . $directory);
            if (in_array('Addon', $subDirectories)) {
                $addons[] = 'Modules/' . $directory;
            }
        }

        $fullData = [];
        foreach ($addons as $item) {
            $info = include(base_path($item . '/Addon/info.php'));
            if ($info['is_published']) {
                $fullData[] = include($item . '/Addon/admin_routes.php');
            }
        }

        return $fullData;
    }

    public function get_payment_publish_status(): array
    {
        $dir = 'Modules';
        $directories = self::getDirectories($dir);
        $addons = [];
        foreach ($directories as $directory) {
            $subDirectories = self::getDirectories($dir . '/' . $directory);
            if ($directory == 'Gateways') {
                if (in_array('Addon', $subDirectories)) {
                    $addons[] = $dir . '/' . $directory;
                }
            }
        }

        $array = [];
        foreach ($addons as $item) {
            $fullData = include(base_path($item . '/Addon/info.php'));
            $array[] = [
                'is_published' => $fullData['is_published'],
            ];
        }

        return $array;
    }

    function getDirectories(string $path): array
    {
        static $localCache = [];

        // Convert to absolute path using Laravel's base_path()
        $absolutePath = base_path($path);

        // Normalize Windows paths (forward/backward slashes)
        $absolutePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $absolutePath);

        if (isset($localCache[$absolutePath])) {
            return $localCache[$absolutePath];
        }

        // Debugging information (remove in production)
        $debugInfo = [
            'input_path' => $path,
            'absolute_path' => $absolutePath,
            'real_path' => realpath($absolutePath) ?: 'NOT RESOLVABLE',
            'directory_exists' => file_exists($absolutePath),
            'is_directory' => is_dir($absolutePath)
        ];

        if (!file_exists($absolutePath)) {
            \Log::error('Directory not found', $debugInfo);
            throw new \RuntimeException("Directory does not exist: {$absolutePath}");
        }

        if (!is_dir($absolutePath)) {
            \Log::error('Path is not a directory', $debugInfo);
            throw new \RuntimeException("Path is not a directory: {$absolutePath}");
        }

        $cacheKey = 'addon_helper_dirs:' . md5($absolutePath);
        $directories = \Illuminate\Support\Facades\Cache::remember($cacheKey, 600, function () use ($absolutePath, $debugInfo) {
            // Try multiple scanning methods for Windows compatibility
            try {
                // First try with default scandir
                $items = scandir($absolutePath);

                // Fallback to Laravel's File facade if scandir fails
                if ($items === false) {
                    $items = \Illuminate\Support\Facades\File::allFiles($absolutePath);
                }
            } catch (\Exception $e) {
                \Log::error('Directory scan failed', array_merge($debugInfo, [
                    'error' => $e->getMessage()
                ]));

                // Final fallback - try DOS command
                $items = [];
                exec('cmd /c dir /b /ad "' . str_replace('/', '\\', $absolutePath) . '"', $items);

                if (empty($items)) {
                    throw new \RuntimeException("All directory scan methods failed for: {$absolutePath}");
                }
            }

            $directories = [];
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                $fullPath = $absolutePath . DIRECTORY_SEPARATOR . $item;

                // Double-check it's a directory (Windows sometimes reports incorrect types)
                if (is_dir($fullPath)) {
                    $directories[] = $item;
                }
            }

            if (config('app.debug')) {
                \Log::debug('Directory scan results', [
                    'path' => $absolutePath,
                    'found_directories' => $directories
                ]);
            }

            return $directories;
        });
        $localCache[$absolutePath] = $directories;

        return $directories;
    }
}
