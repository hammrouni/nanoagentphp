<?php

/**
 * NanoAgent Custom Autoloader
 * 
 * Provides a PSR-4 compliant autoloader for the NanoAgent namespace.
 * This allows the library to be integrated into any project without 
 * requiring Composer for dependency management.
 */

spl_autoload_register(function ($class) {
    // Define the namespace prefix that this autoloader handles.
    $prefix = 'NanoAgent\\';

    // Map the namespace prefix to the directory containing the source files.
    // Given the location of this file (NanoAgent/), the base directory is the current directory.
    $base_dir = __DIR__ . '/';

    // Verify if the class being loaded belongs to the NanoAgent namespace.
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    // Extract the relative class name by removing the prefix.
    $relative_class = substr($class, $len);

    // Transform the relative class name into a file path:
    // 1. Replace namespace separators (\) with directory separators (/).
    // 2. Prefix with the base directory and append the .php extension.
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    // Load the file if it exists on the filesystem.
    if (file_exists($file)) {
        require $file;
    }
});
