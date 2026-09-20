<?php

if (!isset($GLOBALS['_CONF'])) {
    die('This file cannot be used on its own.');
}

/**
 * Thin wrapper around Geeklog's native template layer.
 *
 * Callers remain responsible for escaping values according to their output
 * context. This class only resolves plugin templates and renders variables.
 */
class Videos_TemplateRenderer
{
    public static function render($template, $variables = array(), $subdirectory = '')
    {
        if (!function_exists('COM_newTemplate') ||
            !function_exists('CTL_plugin_templatePath')) {
            return '';
        }

        $template = basename((string) $template);
        if ($template === '' || !preg_match('/\A[A-Za-z0-9_.-]+\z/', $template)) {
            return '';
        }

        $subdirectory = trim((string) $subdirectory, '/\\');
        if ($subdirectory !== '' &&
            !preg_match('/\A[A-Za-z0-9_\/-]+\z/', $subdirectory)) {
            return '';
        }

        $root = CTL_plugin_templatePath('videos', $subdirectory);
        $renderer = COM_newTemplate($root);
        $renderer->set_file('videos_template', $template);

        if (is_array($variables)) {
            foreach ($variables as $name => $value) {
                if (!preg_match('/\A[A-Za-z0-9_]+\z/', (string) $name)) {
                    continue;
                }
                $renderer->set_var((string) $name, (string) $value);
            }
        }

        $renderer->parse('output', 'videos_template');
        return $renderer->finish($renderer->get_var('output'));
    }
}
