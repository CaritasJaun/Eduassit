<?php
defined('BASEPATH') or exit('No direct script access allowed');

if (!function_exists('convert_to_symbol')) {
    function convert_to_symbol($percentage)
    {
        if ($percentage >= 98) return 'A+';
        if ($percentage >= 95) return 'A';
        if ($percentage >= 90) return 'B';
        if ($percentage >= 85) return 'C';
        if ($percentage >= 80) return 'D';
        return 'F';
    }
}
