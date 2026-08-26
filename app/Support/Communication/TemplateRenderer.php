<?php

namespace App\Support\Communication;

/**
 * STEP 17: safe `{{variable}}` substitution only — plain string
 * replacement, never Blade compilation, never eval(), never a callable
 * resolved from the template body. A template can therefore never
 * execute code, no matter what a user types into its body/subject.
 *
 * An unresolved placeholder (a variable name the caller didn't supply)
 * is left as literal `{{name}}` text rather than silently blanked out —
 * that makes a data problem (e.g. a contact with no company on file)
 * visible to the sender before they send, instead of quietly mailing a
 * gap.
 */
class TemplateRenderer
{
    /**
     * @param  array<string, string|null>  $variables
     */
    public function render(string $text, array $variables): string
    {
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function (array $matches) use ($variables) {
            $key = $matches[1];

            if (! array_key_exists($key, $variables) || $variables[$key] === null || $variables[$key] === '') {
                return $matches[0];
            }

            return $variables[$key];
        }, $text);
    }
}
