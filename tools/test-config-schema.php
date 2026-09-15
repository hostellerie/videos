<?php

$_CONF = array(
    'language' => 'english',
    'locale' => 'en_US.UTF-8'
);

require_once dirname(__DIR__) . '/install_defaults.php';

$defaults = videos_default_configuration();
$schema = videos_config_schema();

if (!is_array($defaults) || count($defaults) === 0) {
    fwrite(STDERR, "Videos configuration defaults are empty.\n");
    exit(1);
}
if (!isset($schema['tabs']) || !is_array($schema['tabs']) ||
    !isset($schema['values']) || !is_array($schema['values'])) {
    fwrite(STDERR, "Videos configuration schema is incomplete.\n");
    exit(1);
}

$schemaNames = array();
foreach ($schema['values'] as $definition) {
    if (!is_array($definition) || count($definition) < 5) {
        fwrite(STDERR, "Invalid Videos configuration schema entry.\n");
        exit(1);
    }
    $name = (string) $definition[0];
    $type = (string) $definition[1];
    $fieldset = (int) $definition[2];
    $order = (int) $definition[3];

    if ($name === '' || isset($schemaNames[$name])) {
        fwrite(STDERR, "Duplicate or empty Videos configuration key: $name\n");
        exit(1);
    }
    if (!array_key_exists($name, $defaults)) {
        fwrite(STDERR, "Schema key has no default: $name\n");
        exit(1);
    }
    if ($type !== 'text' && $type !== 'select') {
        fwrite(STDERR, "Unsupported configuration type for $name: $type\n");
        exit(1);
    }
    if ($order <= 0 || !in_array($fieldset, array_values($schema['tabs']), true)) {
        fwrite(STDERR, "Invalid fieldset/order for configuration key: $name\n");
        exit(1);
    }
    $schemaNames[$name] = true;
}

$defaultNames = array_fill_keys(array_keys($defaults), true);
$missingFromSchema = array_diff_key($defaultNames, $schemaNames);
$missingFromDefaults = array_diff_key($schemaNames, $defaultNames);
if (count($missingFromSchema) > 0 || count($missingFromDefaults) > 0) {
    fwrite(
        STDERR,
        'Configuration key mismatch. Defaults-only: '
        . implode(', ', array_keys($missingFromSchema))
        . '; schema-only: '
        . implode(', ', array_keys($missingFromDefaults))
        . PHP_EOL
    );
    exit(1);
}

$source = file_get_contents(dirname(__DIR__) . '/install_defaults.php');
if ($source === false ||
    strpos($source, '$schema = videos_config_schema();') === false ||
    strpos($source, "foreach ($schema['tabs'] as $name => $fieldset)") === false ||
    strpos($source, "foreach ($schema['values'] as $definition)") === false) {
    fwrite(STDERR, "Initial configuration installation is not schema-driven.\n");
    exit(1);
}

$initStart = strpos($source, 'function plugin_initconfig_videos(');
$schemaStart = strpos($source, 'function videos_config_schema()');
if ($initStart === false || $schemaStart === false || $schemaStart <= $initStart) {
    fwrite(STDERR, "Unable to inspect plugin_initconfig_videos().\n");
    exit(1);
}
$initSource = substr($source, $initStart, $schemaStart - $initStart);
if (preg_match("/videos_config_add_value\\(\\s*\\$c\\s*,\\s*['\"]/", $initSource)) {
    fwrite(STDERR, "plugin_initconfig_videos() still contains manual configuration definitions.\n");
    exit(1);
}

echo 'Videos configuration schema: OK (' . count($schemaNames)
    . ' settings, ' . count($schema['tabs']) . ' tabs)' . PHP_EOL;
