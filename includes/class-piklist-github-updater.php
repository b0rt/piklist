<?php

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * Piklist_GitHub_Updater
 * Serves plugin updates directly from the GitHub repository instead of WordPress.org.
 *
 * Only published GitHub releases are offered as updates; the release tag
 * (e.g. v1.0.13) determines the version and the release zipball is the
 * update package.
 *
 * @package     Piklist
 * @subpackage  GitHub_Updater
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       1.0
 */
class Piklist_GitHub_Updater
{
  /**
   * @var string The GitHub repository (owner/name) updates are pulled from.
   * @access public
   */
  public static $repository = 'b0rt/piklist';

  /**
   * @var string Site transient key used to cache the latest version lookup.
   * @access private
   */
  private static $cache_key = 'piklist_github_update';

  /**
   * _construct
   * Class constructor.
   *
   * @access public
   * @static
   * @since 1.0
   */
  public static function _construct()
  {
    add_filter('pre_set_site_transient_update_plugins', array('piklist_github_updater', 'check_update'));

    add_filter('plugins_api', array('piklist_github_updater', 'plugins_api'), 10, 3);

    add_filter('upgrader_source_selection', array('piklist_github_updater', 'upgrader_source_selection'), 10, 4);

    add_action('upgrader_process_complete', array('piklist_github_updater', 'purge_cache'), 10, 2);
  }

  /**
   * plugin_basename
   * The plugin basename as WordPress tracks it (e.g. piklist/piklist.php).
   *
   * @return string The plugin basename.
   *
   * @access public
   * @static
   * @since 1.0
   */
  public static function plugin_basename()
  {
    return plugin_basename(dirname(dirname(__FILE__)) . '/piklist.php');
  }

  /**
   * check_update
   * Injects the latest GitHub version into the plugin update transient and
   * drops any update WordPress.org may have offered for the piklist slug.
   *
   * @param object $transient The update_plugins site transient.
   *
   * @return object The update_plugins site transient.
   *
   * @access public
   * @static
   * @since 1.0
   */
  public static function check_update($transient)
  {
    if (empty($transient) || !is_object($transient))
    {
      return $transient;
    }

    $plugin = self::plugin_basename();
    $version = piklist::$version ? piklist::$version : '0';
    $latest = self::latest();

    if (!$latest)
    {
      return $transient;
    }

    $update = (object) array(
      'id' => 'https://github.com/' . self::$repository
      ,'slug' => 'piklist'
      ,'plugin' => $plugin
      ,'new_version' => $latest['version']
      ,'url' => 'https://github.com/' . self::$repository
      ,'package' => $latest['package']
      ,'icons' => array()
      ,'banners' => array()
      ,'banners_rtl' => array()
      ,'tested' => ''
      ,'requires_php' => ''
      ,'compatibility' => new stdClass()
    );

    if (version_compare($latest['version'], $version, '>'))
    {
      $transient->response[$plugin] = $update;

      unset($transient->no_update[$plugin]);
    }
    else
    {
      // Up to date; make sure WordPress.org cannot overwrite this install
      unset($transient->response[$plugin]);

      $update->new_version = $version;
      $transient->no_update[$plugin] = $update;
    }

    return $transient;
  }

  /**
   * plugins_api
   * Provides the plugin information popup from GitHub data.
   *
   * @param false|object|array $result The result object or array.
   * @param string $action The type of information being requested.
   * @param object $arguments Plugin API arguments.
   *
   * @return false|object The plugin information.
   *
   * @access public
   * @static
   * @since 1.0
   */
  public static function plugins_api($result, $action, $arguments)
  {
    if ($action != 'plugin_information' || !isset($arguments->slug) || $arguments->slug != 'piklist')
    {
      return $result;
    }

    $latest = self::latest();

    if (!$latest)
    {
      return $result;
    }

    return (object) array(
      'name' => 'Piklist'
      ,'slug' => 'piklist'
      ,'version' => $latest['version']
      ,'author' => '<a href="https://piklist.com">Piklist</a>'
      ,'homepage' => 'https://github.com/' . self::$repository
      ,'download_link' => $latest['package']
      ,'sections' => array(
        'description' => __('The most powerful framework available for WordPress.', 'piklist')
        ,'changelog' => !empty($latest['notes']) ? wpautop($latest['notes']) : sprintf(__('See the commit history at %s', 'piklist'), 'https://github.com/' . self::$repository . '/commits')
      )
    );
  }

  /**
   * upgrader_source_selection
   * Renames the extracted GitHub archive folder (e.g. b0rt-piklist-abc1234)
   * to the expected plugin folder name so the update replaces this plugin.
   *
   * @param string $source File source location.
   * @param string $remote_source Remote file source location.
   * @param object $upgrader The WP_Upgrader instance.
   * @param array $hook_extra Extra arguments passed to hooked filters.
   *
   * @return string|WP_Error The corrected source location.
   *
   * @access public
   * @static
   * @since 1.0
   */
  public static function upgrader_source_selection($source, $remote_source, $upgrader, $hook_extra = array())
  {
    global $wp_filesystem;

    if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] != self::plugin_basename())
    {
      return $source;
    }

    $corrected = trailingslashit($remote_source) . 'piklist';

    if (untrailingslashit($source) == $corrected)
    {
      return $source;
    }

    if ($wp_filesystem->move(untrailingslashit($source), $corrected))
    {
      return trailingslashit($corrected);
    }

    return new WP_Error('piklist_github_updater', __('Could not rename the GitHub update folder.', 'piklist'));
  }

  /**
   * purge_cache
   * Clears the cached version lookup after this plugin was updated.
   *
   * @param object $upgrader The WP_Upgrader instance.
   * @param array $options Details about the completed update.
   *
   * @access public
   * @static
   * @since 1.0
   */
  public static function purge_cache($upgrader, $options)
  {
    if (isset($options['type']) && $options['type'] == 'plugin')
    {
      delete_site_transient(self::$cache_key);
    }
  }

  /**
   * latest
   * The latest available version and package url, cached for six hours.
   *
   * @return array|false Array with version, package and notes, or false.
   *
   * @access public
   * @static
   * @since 1.0
   */
  public static function latest()
  {
    $latest = get_site_transient(self::$cache_key);

    if ($latest === false)
    {
      $latest = self::fetch_latest();

      set_site_transient(self::$cache_key, $latest ? $latest : 'none', 6 * HOUR_IN_SECONDS);
    }

    return is_array($latest) ? $latest : false;
  }

  /**
   * fetch_latest
   * Looks up the latest published (non-draft, non-prerelease) release on GitHub.
   *
   * @return array|false Array with version, package and notes, or false.
   *
   * @access private
   * @static
   * @since 1.0
   */
  private static function fetch_latest()
  {
    $response = wp_remote_get('https://api.github.com/repos/' . self::$repository . '/releases/latest', array(
      'timeout' => 10
      ,'headers' => array(
        'Accept' => 'application/vnd.github+json'
      )
    ));

    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) == 200)
    {
      $release = json_decode(wp_remote_retrieve_body($response), true);

      if (!empty($release['tag_name']))
      {
        return array(
          'version' => ltrim($release['tag_name'], 'vV')
          ,'package' => 'https://api.github.com/repos/' . self::$repository . '/zipball/' . rawurlencode($release['tag_name'])
          ,'notes' => isset($release['body']) ? $release['body'] : ''
        );
      }
    }

    return false;
  }
}
