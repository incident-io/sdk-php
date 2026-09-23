<?php

/**
 * Load every generated class and check the public API surface.
 *
 *     php scripts/verify.php check api-surface.txt
 *     php scripts/verify.php write api-surface.txt
 *
 * Loading: php -l only parses one file at a time. It does not notice a class
 * that extends or implements something missing, or a namespace that disagrees
 * with the path, and PHP does not load a class until something uses it, so a
 * consumer would find those at runtime. Loading every class through Composer's
 * autoloader is the nearest PHP has to a compile.
 *
 * Surface: oasdiff compares the wire contract. Several schema changes that it
 * rates info rename something PHP callers use: renaming a component schema
 * renames a model class, a changed operationId or tag renames a method or an
 * Api class, and a renamed path parameter renames a named argument.
 * api-surface.txt records one line per name, so an addition never rewrites an
 * existing line, and `check` fails if any recorded line has disappeared. The
 * release runs `write` after a passing check, so additions are recorded too.
 *
 * Parameters are recorded by name, not position. The API inserts new optional
 * parameters among the existing ones, which moves the later ones along and
 * breaks positional calls. Replaying sdk-go's 124 schema changes, recording
 * positions halted 7 releases on that alone. Named arguments are unaffected,
 * so the README tells callers to use them, and the contract here is the
 * names, as it is for the Python SDK's keyword arguments.
 */

declare(strict_types=1);

// Real figure is about 1200.
const CLASS_FLOOR = 1000;

[, $mode, $baseline] = $argv + [null, null, null];
if (!in_array($mode, ['check', 'write'], true) || $baseline === null) {
    fwrite(STDERR, "usage: php scripts/verify.php check|write api-surface.txt\n");
    exit(2);
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$classes = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $relative = substr($file->getPathname(), strlen($root . '/src/'), -strlen('.php'));
    $class = 'IncidentIo\\' . str_replace('/', '\\', $relative);
    try {
        $exists = class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class);
    } catch (Throwable $e) {
        // A ParseError or a missing parent class. Fatal errors that cannot be
        // caught still stop the process with a non-zero status.
        fwrite(STDERR, "error: loading {$class}: " . $e::class . ": {$e->getMessage()}\n");
        exit(1);
    }
    if (!$exists) {
        fwrite(STDERR, "error: {$file->getPathname()} does not define {$class}\n");
        exit(1);
    }
    $classes[] = $class;
}
sort($classes);

if (count($classes) < CLASS_FLOOR) {
    fwrite(STDERR, sprintf("error: only %d classes loaded (floor %d)\n", count($classes), CLASS_FLOOR));
    exit(1);
}

$surface = [];
foreach ($classes as $class) {
    $reflection = new ReflectionClass($class);
    $surface[] = "class {$class}";

    foreach ($reflection->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
        if ($constant->getDeclaringClass()->getName() === $class) {
            $surface[] = "const {$class}::{$constant->getName()}";
        }
    }

    // Model properties, which name the constructor's array keys and the
    // get/set methods.
    if ($reflection->implementsInterface(\IncidentIo\Model\ModelInterface::class) && !$reflection->isInterface()) {
        foreach (array_keys($class::openAPITypes()) as $property) {
            $surface[] = "property {$class} {$property}";
        }
        continue;
    }

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== $class) {
            continue;
        }
        $name = $method->getName();
        $surface[] = "method {$class}::{$name}";
        foreach ($method->getParameters() as $parameter) {
            $surface[] = sprintf('param %s::%s $%s', $class, $name, $parameter->getName());
        }
    }
}
$surface = array_unique($surface);
sort($surface);

if ($mode === 'write') {
    file_put_contents($baseline, implode("\n", $surface) . "\n");
    printf("%d classes loaded; wrote %d surface lines to %s\n", count($classes), count($surface), $baseline);
    exit(0);
}

if (!file_exists($baseline)) {
    fwrite(STDERR, "error: {$baseline} does not exist; run `make surface` to create it\n");
    exit(1);
}
$recorded = file($baseline, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$missing = array_values(array_diff($recorded, $surface));

if ($missing !== []) {
    fwrite(STDERR, sprintf("error: %d recorded API surface lines are gone:\n", count($missing)));
    foreach (array_slice($missing, 0, 50) as $line) {
        fwrite(STDERR, "  {$line}\n");
    }
    if (count($missing) > 50) {
        fwrite(STDERR, sprintf("  ... and %d more\n", count($missing) - 50));
    }
    fwrite(STDERR, "\nThis breaks code that uses them. If it is deliberate, it needs a major\n");
    fwrite(STDERR, "release: see CONTRIBUTING.md.\n");
    exit(1);
}

printf(
    "%d classes loaded; all %d recorded surface lines present, %d new\n",
    count($classes),
    count($recorded),
    count($surface) - count($recorded)
);
