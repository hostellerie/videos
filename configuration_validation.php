<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

function videos_validate_boolean($value)
{
    return ($value === 0 || $value === 1 ||
            $value === '0' || $value === '1');
}

function videos_validate_integer_range($value, $minimum, $maximum)
{
    $filtered = filter_var($value, FILTER_VALIDATE_INT);
    return ($filtered !== false &&
            $filtered >= $minimum &&
            $filtered <= $maximum);
}

function videos_validate_language($value)
{
    return (bool) preg_match('/^[a-z]{2,3}(?:-[A-Za-z]{2,8})?$/', $value);
}

function videos_validate_region($value)
{
    return (bool) preg_match('/^[A-Z]{2}$/', $value);
}

function videos_validate_csv($value, $maximumLength)
{
    return is_string($value) && strlen($value) <= $maximumLength;
}

function videos_validate_account_retention($value)
{
    return ((int) $value === 0);
}

function videos_validate_short_max_duration($value)
{
    return videos_validate_integer_range($value, 1, 600);
}

function videos_validate_short_filter_mode($value)
{
    return in_array($value, array('probable', 'strict'), true);
}

function videos_validate_permanent_pool_size($value)
{
    return videos_validate_integer_range($value, 1, 100);
}

function videos_validate_permanent_pool_percentage($value)
{
    return videos_validate_integer_range($value, 0, 50);
}

function videos_validate_permanent_pool_min_ratings($value)
{
    return videos_validate_integer_range($value, 1, 1000);
}

function videos_validate_permanent_pool_min_weighted_rating($value)
{
    return is_numeric($value) && (float) $value >= 0 &&
        (float) $value <= 5;
}

function videos_validate_automatic_account_purge($value)
{
    return ((int) $value === 0);
}

function videos_validate_seo_description_fallback($value)
{
    return is_string($value) && strlen($value) <= 300 &&
        $value === strip_tags($value);
}
