<?php  if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * flash() – tiny shortcut to echo Bootstrap alerts
 *   flash('success','success')  → looks for $_SESSION['success']
 *   flash('error','danger')     → looks for $_SESSION['error']
 */
function flash($key, $type='info')
{
    $CI =& get_instance();
    $msg = $CI->session->flashdata($key);
    if ($msg) {
        echo '<div class="alert alert-'.$type.'">'.$msg.'</div>';
    }
}
