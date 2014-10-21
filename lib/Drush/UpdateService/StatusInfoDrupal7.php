<?php

/**
 * @file
 * Implementation of 'drupal' update_status engine for Drupal 7.
 */

namespace Drush\UpdateService;

class StatusInfoDrupal7 extends StatusInfoDrupal8 {

  /**
   * {@inheritdoc}
   */
  function lastCheck() {
    return variable_get('update_last_check', 0);
  }

  /**
   * Get update information for all installed projects.
   *
   * @see update_get_available().
   * @see update_manual_status().
   *
   * * @return Array containing remote and local versions
   * for all installed projects.
   */
  function getStatus($projects) {
    // Force to invalidate some caches that are only cleared
    // when visiting update status report page. This allow to detect changes in
    // .info files.
    _update_cache_clear('update_project_data');
    _update_cache_clear('update_project_projects');

    // From update_get_available(): Iterate all projects and create a fetch task
    // for those we have no information or is obsolete.
    module_load_include('inc', 'update', 'update.compare');
    $available = _update_get_cached_available_releases();

    $update_projects = update_get_projects();

    foreach ($update_projects as $key => $project) {
      if (empty($available[$key])) {
        update_create_fetch_task($project);
        continue;
      }
      if ($project['info']['_info_file_ctime'] > $available[$key]['last_fetch']) {
        $available[$key]['fetch_status'] = UPDATE_FETCH_PENDING;
      }
      if (empty($available[$key]['releases'])) {
        $available[$key]['fetch_status'] = UPDATE_FETCH_PENDING;
      }
      if (!empty($available[$key]['fetch_status']) && $available[$key]['fetch_status'] == UPDATE_FETCH_PENDING) {
        update_create_fetch_task($project);
      }
    }

    // Set a batch to process all pending tasks.
    $batch = array(
      'operations' => array(
        array('update_fetch_data_batch', array()),
      ),
      'finished' => 'update_fetch_data_finished',
      'file' => drupal_get_path('module', 'update') . '/update.fetch.inc',
    );
    batch_set($batch);
    drush_backend_batch_process();

    // Clear any error set by a failed update fetch task. This avoid rollbacks.
    drush_clear_error();

    // Calculate update status data.
    $available = _update_get_cached_available_releases();
    $data = update_calculate_project_data($available);
    foreach ($data as $project_name => $project) {
      // Discard custom projects.
      if ($project['status'] == UPDATE_UNKNOWN) {
        unset($data[$project_name]);
        continue;
      }
      // Discard projects with unknown installation path.
      if ($project_name != 'drupal' && !isset($projects[$project_name]['path'])) {
        unset($data[$project_name]);
        continue;
      }
      // Allow to update disabled projects.
      if (in_array($project['project_type'], array('module-disabled', 'theme-disabled'))) {
        $data[$project_name]['project_type'] = substr($project['project_type'], 0, strpos($project['project_type'], '-'));
      }
      // Add some info from the project to $data.
      $data[$project_name] += array(
        'path'  => isset($projects[$project_name]['path']) ? $projects[$project_name]['path'] : '',
        'label' => $projects[$project_name]['label'],
      );
      // Store all releases, not just the ones selected by update.module.
      if (isset($available[$project_name]['releases'])) {
        $data[$project_name]['releases'] = $available[$project_name]['releases'];
      }
    }

    return $data;
  }
}
