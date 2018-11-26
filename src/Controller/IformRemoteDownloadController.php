<?php

namespace Drupal\iform_remote_download\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Remote download controller class.
 *
 * Controller for web service endpoints for remotely downloading records, e.g.
 * into Recorder 6.
 */
class IformRemoteDownloadController extends ControllerBase {

  /**
   * Controller function for remotely logging in.
   *
   * @return string
   *   A response containing the user's secret, plus first name and last name.
   */
  public function userLogin() {
    $email = $_POST['email'];
    $password = $_POST['password'];
    $appsecret = $_POST['appsecret'];
    $config = \Drupal::config('iform_remote_download.settings');
    $storedSecret = $config->get('appsecret');
    // Step 1.
    // Check minimum valid parameters.
    if (empty($email) || empty($password) || empty($appsecret)) {
      return new JsonResponse(json_encode([
        'status' => 'Bad request',
        'message' => 'Bad request - missing parameter',
      ]), 400);
    }
    // Step 2.
    // Verify APP shared secret.
    if ($appsecret !== $storedSecret) {
      \Drupal::logger('iform_remote_download')->notice('Missing or incorrect shared app secret');
      return new JsonResponse(json_encode([
        'status' => 'Bad request',
        'message' => 'Bad request - missing or incorrect app secret',
      ]), 400);
    }
    $existingUser = user_load_by_mail($email);
    // Check user exists.
    if (!$existingUser) {
      return new JsonResponse(json_encode([
        'status' => 'Invalid email',
        'message' => 'Invalid email',
      ]), 401);
    }
    // Check password OK.
    $uid = \Drupal::service('user.auth')->authenticate($existingUser->getUserName(), $password);
    if (!$uid) {
      return new JsonResponse(json_encode([
        'status' => 'Invalid password',
        'message' => 'Invalid password',
      ]), 401);
    }
    // If no shared secret in the account, create one.
    if (empty($existingUser->field_iform_auth_shared_secret->value)) {
      $existingUser->field_iform_auth_shared_secret->setValue($this->getRandomString());
      $existingUser->save();
    }
    // Return the shared secret with other user data.
    return new Response(
      $existingUser->field_iform_auth_shared_secret->value . "\n" .
      $existingUser->field_first_name->value . "\n" .
      $existingUser->field_last_name->value
    );
  }

  /**
   * Controller function for obtaining a user's privileges.
   *
   * @return string
   *   Json containing the types of download available to the user and the
   *   surveys available to download from.
   */
  public function userPrivileges() {
    \Drupal::logger('iform_remote_download')->notice('Authenticating');
    $response = $this->getAuthenticationResponse();
    if ($response instanceof JsonResponse) {
      return $response;
    }
    $user = $response;
    \Drupal::logger('iform_remote_download')->notice('Authenticated');

    $types = ['my-records'];
    if ($user->hasPermission('verification')) {
      $types[] = 'expert-records';
    }
    if ($user->hasPermission('collate regional records')) {
      $types[] = 'collate-records';
    }

    $config = \Drupal::config('iform.settings');
    iform_load_helpers(['data_entry_helper']);
    // Read the available surveys from the warehouse.
    $readAuth = \data_entry_helper::get_read_auth($config->get('website_id'), $config->get('password'));
    $data = \data_entry_helper::get_population_data([
      'table' => 'survey',
      'extraParams' => $readAuth + [
        'sharing' => 'data_flow',
        'orderby' => 'website,title',
        'view' => 'detail',
      ],
    ]);
    $surveys = [];
    foreach ($data as $survey) {
      $surveyTitle = strcasecmp(substr($survey['website'], 0, strlen($survey['title'])), $survey['title']) === 0
        ? $survey['title'] : "$survey[website] $survey[title]";
      $surveys[$survey['id']] = $surveyTitle;
    }
    \Drupal::logger('iform_remote_download')->notice('Privileges :' . json_encode([
      'types' => $types,
      'surveys' => $surveys,
    ]));
    $response = $this->getAuthenticationResponse();
    return new JsonResponse([
      'types' => $types,
      'surveys' => $surveys,
    ], 200, ['Content-type', 'application/json; charset=UTF-8']);
  }

  /**
   * Download controller endpoint.
   *
   * @return string
   *   Json response containing the downloaded records.
   */
  public function download() {
    $response = $this->getAuthenticationResponse();
    if ($response instanceof JsonResponse) {
      return $response;
    }
    $user = $response;
    $type = $_POST['type'];
    $report = $type === 'collate-records' ? 'remote_download_by_input_date_using_spatial_index_builder' : 'remote_download';
    $config = \Drupal::config('iform.settings');
    iform_load_helpers(['report_helper']);
    $readAuth = \report_helper::get_read_auth($config->get('website_id'), $config->get('password'));
    try {
      $location_id = $type === 'collate-records' ? $user->field_location_collation->value : $user->field_location_expertise->value;
    }
    catch (Exception $e) {
      $location_id = '';
    }
    $options = [
      'dataSource' => "library/occurrences/$report",
      'readAuth' => $readAuth,
      'extraParams' => [
        'date_from' => $_POST['date_from'],
        'date_to' => $_POST['date_to'],
        'quality' => empty($_POST['quality']) ? '!R' : $_POST['quality'],
        'smpattrs' => empty($_POST['smpAttrs']) ? '' : $_POST['smpAttrs'],
        'occattrs' => empty($_POST['occAttrs']) ? '' : $_POST['occAttrs'],
        'searchArea' => '',
        'idlist' => '',
        'ownData' => $type === 'my-records' ? 1 : 0,
        'currentUser' => $user->field_indicia_user_id->value,
        'ownLocality' => $type === 'my-records' || empty($location_id) ? 0 : 1,
        'location_id' => $location_id,
        'taxon_groups' => '',
        'ownGroups' => 0,
        'surveys' => empty($_POST['survey_id']) ? '' : $_POST['survey_id'],
        'ownSurveys' => empty($_POST['survey_id']) ? 0 : 1,
        'uploadFolder' => \report_helper::$base_url . 'upload/',
      ],
      'sharing' => 'data_flow',
    ];
    if (!empty($_POST['offset'])) {
      $options['extraParams']['offset'] = $_POST['offset'];
    }
    if (!empty($_POST['limit'])) {
      $options['extraParams']['limit'] = $_POST['limit'];
    }
    if (!empty($_POST['wantCount'])) {
      $options['extraParams']['wantCount'] = $_POST['wantCount'];
    }
    $records = \report_helper::get_report_data($options);
    return new JsonResponse($records, 200, ['Content-type', 'application/json; charset=UTF-8']);
  }

  /**
   * A simple utility method to generate a random string of specific length.
   *
   * @param int $length
   *   The length of string required.
   *
   * @return string
   *   A random string.
   */
  private function getRandomString($length = 10) {
    return substr(str_shuffle("0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, $length);
  }

  /**
   * Authenticates the download appsecret, usersecret are correct.
   *
   * @return bool
   *   Returns TRUE if OK.
   */
  private function getAuthenticationResponse() {
    $usersecret = $_POST['usersecret'];
    $appsecret = $_POST['appsecret'];
    $email = $_POST['email'];
    $config = \Drupal::config('iform_remote_download.settings');
    $storedSecret = $config->get('appsecret');
    // Step 1.
    // Verify APP shared secret.
    if ($appsecret !== $storedSecret) {
      \Drupal::logger('iform_remote_download')->notice('Missing or incorrect shared app secret');
      return new JsonResponse([
        'status' => 'Bad request',
        'message' => 'Bad request - missing or incorrect app secret',
      ], 400);
    }
    // Step 2.
    // Locate corresponding user.
    $existingUser = user_load_by_mail($email);
    if ($existingUser === FALSE) {
      \Drupal::logger('iform_remote_download')->notice('Incorrect email');
      return new JsonResponse([
        'status' => 'Bad request',
        'message' => 'Bad request - incorrect email',
      ], 400);
    }
    // Step 3.
    // Verify USER shared secret...
    if (empty($usersecret) || trim($usersecret) !== trim($existingUser->field_iform_auth_shared_secret->value)) {
      \Drupal::logger('iform_remote_download')->notice('User secret incorrect');
      return new JsonResponse([
        'status' => 'Bad request',
        'message' => 'Bad request - user secret incorrect',
      ], 400);
    }
    // Step 4.
    // Check user activation status.
    if ($existingUser->isBlocked()) {
      \Drupal::logger('iform_remote_download')->notice('User not activated');
      return new JsonResponse([
        'status' => 'Forbidden',
        'message' => 'Forbidden - User not activated',
      ], 403);
    }
    \Drupal::logger('iform_remote_download')->notice('Got user ' . $existingUser->id());
    return $existingUser;
  }

}
