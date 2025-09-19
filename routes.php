<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| -------------------------------------------------------------------------
| URI ROUTING
| -------------------------------------------------------------------------
| This file lets you re-map URI requests to specific controller functions.
|
| Typically there is a one-to-one relationship between a URL string
| and its corresponding controller class/method. The segments in a
| URL normally follow this pattern:
|
|	example.com/class/method/id/
|
| In some instances, however, you may want to remap this relationship
| so that a different class/function is called than the one
| corresponding to the URL.
|
| Please see the user guide for complete details:
|
|	https://codeigniter.com/user_guide/general/routing.html
|
| -------------------------------------------------------------------------
| RESERVED ROUTES
| -------------------------------------------------------------------------
|
| There are three reserved routes:
|
|	$route['default_controller'] = 'welcome';
|
| This route indicates which controller class should be loaded if the
| URI contains no data. In the above example, the "welcome" class
| would be loaded.
|
|	$route['404_override'] = 'errors/page_missing';
|
| This route will tell the Router which controller/method to use if those
| provided in the URL cannot be matched to a valid route.
|
|	$route['translate_uri_dashes'] = FALSE;
|
| This is not exactly a route, but allows you to automatically route
| controller and method names that contain dashes. '-' isn't a valid
| class or method name character, so it requires translation.
| When you set this option to TRUE, it will replace ALL dashes in the
| controller and method URI segments.
|
| Examples:	my-controller/index	-> my_controller/index
|		my-controller/my-method	-> my_controller/my_method
*/

spl_autoload_register(function($className) {
    if ( substr($className, -6) == "_Addon" ) {
        $file = APPPATH . 'core/' . $className . '.php';
        if ( file_exists($file) && is_file($file) ) {
            @include_once( $file );
        }
    }
});

$routes_path = APPPATH . 'config/my_routes/';
if (is_dir($routes_path)) {
	$routes = scandir($routes_path);
	foreach ($routes as $r_file)
	{
	    if ($r_file === '.' || $r_file === '..' || $r_file === 'index.html') {
	        continue;
	    }
        $route_path = $routes_path . $r_file;
        if (file_exists($route_path)) {
            @include_once $route_path; 
        }
	} 
}

$route['(:any)/authentication'] = 'authentication/index/$1';
$route['(:any)/forgot'] = 'authentication/forgot/$1';
$route['(:any)/teachers'] = 'home/teachers';
$route['(:any)/events'] = 'home/events';
$route['(:any)/news'] = 'home/news/';
$route['(:any)/about'] = 'home/about';
$route['(:any)/faq'] = 'home/faq';
$route['(:any)/admission'] = 'home/admission';
$route['(:any)/gallery'] = 'home/gallery';
$route['(:any)/contact'] = 'home/contact';
$route['(:any)/admit_card'] = 'home/admit_card';
$route['(:any)/exam_results'] = 'home/exam_results';
$route['(:any)/certificates'] = 'home/certificates';
$route['(:any)/page/(:any)'] = 'home/page/$2';
$route['(:any)/gallery_view/(:any)'] = 'home/gallery_view/$2';
$route['(:any)/event_view/(:num)'] = 'home/event_view/$2';
$route['(:any)/news_view/(:any)'] = 'home/news_view/$2';

$route['dashboard'] = 'dashboard/index';
$route['branch'] = 'branch/index';
$route['attachments'] = 'attachments/index';
$route['homework'] = 'homework/index';
$route['onlineexam'] = 'onlineexam/index';
$route['hostels'] = 'hostels/index';
$route['event'] = 'event/index';
$route['accounting'] = 'accounting/index';
$route['school_settings'] = 'school_settings/index';
$route['role'] = 'role/index';
$route['sessions'] = 'sessions/index';
$route['translations'] = 'translations/index';
$route['cron_api'] = 'cron_api/index';
$route['modules'] = 'modules/index';
$route['system_student_field'] = 'system_student_field/index';
$route['custom_field'] = 'custom_field/index';
$route['backup'] = 'backup/index';
$route['advance_salary'] = 'advance_salary/index';
$route['system_update'] = 'system_update/index';
$route['certificate'] = 'certificate/index';
$route['payroll'] = 'payroll/index';
$route['leave'] = 'leave/index';
$route['award'] = 'award/index';
$route['classes'] = 'classes/index';
$route['student_promotion'] = 'student_promotion/index';
$route['live_class'] = 'live_class/index';
$route['exam'] = 'exam/index';
$route['profile'] = 'profile/index';
$route['sections'] = 'sections/index';

$route['authentication'] = 'authentication/index';
$route['install'] = 'install/index';
$route['404_override'] = 'errors';
if (!empty($saas_default) && $saas_default == true) {
	$route['default_controller'] = 'saas_website/index';
} else {
	$route['default_controller'] = 'home';
}
$route['translate_uri_dashes'] = FALSE;
$route['pace/mark_completed'] = 'pace/mark_completed';
$route['pace/update_status'] = 'pace/update_status';
$route['pace/assign']        = 'pace/assign';
$route['pace/assign_save']   = 'pace/assign_save';
$route['subjectpace']             = 'subjectpace/index';
$route['subjectpace/save']        = 'subjectpace/save';
$route['subjectpace/delete/(:num)']= 'subjectpace/delete/$1';
$route['monitor_goal_check'] = 'monitor_goal_check/index';
$route['monitor_goal_check/set_term_dates'] = 'monitor_goal_check/set_term_dates';
$route['monitor_goal_check/save_term_date'] = 'monitor_goal_check/save_term_date';
$route['monitor_goal_check/update_term_end_date'] = 'monitor_goal_check/update_term_end_date';
$route['spc/save_reading_program'] = 'spc/save_reading_program';
$route['weekly_traits']       = 'weekly_traits/index';
$route['weekly_traits/save']  = 'weekly_traits/save';
$route['pace/orders_batches']     = 'pace/orders_batches';
$route['pace/batch_update_status'] = 'pace/batch_update_status';
$route['notification']                  = 'notification/index';
$route['notification/open/(:num)']      = 'notification/open/$1';
$route['notification/clear']            = 'notification/clear';
$route['notification/mark_read/(:num)'] = 'notification/mark_read/$1';
$route['pace/order_batches'] = 'pace/orders_batches';
$route['event/calendar']   = 'event/calendar';
$route['event/feed']       = 'event/feed';
$route['event/save_quick'] = 'event/save_quick';
$route['pace/update_assign_status'] = 'pace/update_assign_status';



$route['employee'] = 'employee/index';
$route['employee/(:any)'] = 'employee/$1';
$route['designation'] = 'designation/index';
$route['designation/(:any)'] = 'designation/$1';
$route['department'] = 'department/index';
$route['department/(:any)'] = 'department/$1';

$route['(:any)'] = 'home/index/$1';

$route['event/get_events_list'] = 'event/get_events_list';

