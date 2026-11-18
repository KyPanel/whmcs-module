<?php

namespace KyPanel\Whmcs;

// translations live in lang/<language>.php, keyed by the English text
class Lang
{
    private static $strings;

    public static function t(string $text, array $vars = []): string
    {
        if (self::$strings === null) {
            self::$strings = self::load();
        }
        $out = self::$strings[$text] ?? $text;
        foreach ($vars as $name => $value) {
            $out = str_replace('{' . $name . '}', (string) $value, $out);
        }
        return $out;
    }

    private static function load(): array
    {
        foreach (self::candidates() as $name) {
            $name = strtolower(preg_replace('/[^A-Za-z_-]/', '', (string) $name));
            $file = __DIR__ . '/../lang/' . $name . '.php';
            if ($name !== '' && is_file($file)) {
                $strings = include $file;
                return is_array($strings) ? $strings : [];
            }
        }
        return [];
    }

    private static function candidates(): array
    {
        $names = [];
        $class = defined('ADMINAREA') ? '\AdminLang' : '\Lang';
        if (class_exists($class) && method_exists($class, 'self')) {
            try {
                $lang = $class::self();
                if (is_object($lang) && method_exists($lang, 'getName')) {
                    $names[] = $lang->getName();
                }
            } catch (\Throwable $e) {
            }
        }
        if (defined('ADMINAREA')) {
            $names[] = $_SESSION['adminlang'] ?? '';
        } else {
            $names[] = $_SESSION['Language'] ?? '';
        }
        $names[] = $GLOBALS['CONFIG']['Language'] ?? '';
        return array_filter($names);
    }
}
