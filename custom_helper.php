<?php
defined('BASEPATH') or exit('No direct script access allowed');

if ( ! function_exists('get_loggedin_role_id')) {
    function get_loggedin_role_id()
    {
        $CI =& get_instance();
        return $CI->session->userdata('loggedin_role_id');
    }
}

if ( ! function_exists('access_denied')) {
    function access_denied()
    {
        redirect(base_url('authentication'), 'refresh');
        exit;
    }
}
