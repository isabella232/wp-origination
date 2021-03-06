<?php
/**
 * File_Locator Class.
 *
 * @package   Google\WP_Origination
 * @link      https://github.com/GoogleChromeLabs/wp-origination
 * @license   https://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 * @copyright 2019 Google LLC
 */

namespace Google\WP_Origination;

/**
 * Class File_Locator.
 */
class File_Locator {

	/**
	 * Core directory.
	 *
	 * @var string
	 */
	public $core_directory;

	/**
	 * Directories for plugins.
	 *
	 * @var array
	 */
	public $plugins_directories = [];

	/**
	 * Directory for mu-plugins.
	 *
	 * @var string
	 */
	public $mu_plugins_directory;

	/**
	 * Current theme.
	 *
	 * @var \WP_Theme
	 */
	public $current_theme;

	/**
	 * Cached file locations.
	 *
	 * @see File_Locator::identify_file_location()
	 * @var array[]
	 */
	protected $cached_file_locations = [];

	/**
	 * File_Locator constructor.
	 */
	public function __construct() {
		$this->core_directory        = trailingslashit( wp_normalize_path( ABSPATH ) );
		$this->current_theme         = wp_get_theme();
		$this->plugins_directories[] = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );
		$this->mu_plugins_directory  = trailingslashit( wp_normalize_path( WPMU_PLUGIN_DIR ) );
	}

	/**
	 * Identify the location for a given file.
	 *
	 * @param string $file File.
	 * @return array|null {
	 *     Location information, or null if no location could be identified.
	 *
	 *     @var string               $type The type of location, either core, plugin, mu-plugin, or theme.
	 *     @var string               $name The name of the entity, such as 'twentyseventeen' or 'amp/amp.php'.
	 *     @var \WP_Theme|array|null $data Additional data about the entity, such as the theme object or plugin data.
	 * }
	 */
	public function identify( $file ) {

		if ( isset( $this->cached_file_locations[ $file ] ) ) {
			return $this->cached_file_locations[ $file ];
		}

		$file         = wp_normalize_path( $file );
		$slug_pattern = '(?P<root_slug>[^/]+)';

		if ( preg_match( ':' . preg_quote( $this->core_directory, ':' ) . '(wp-admin|wp-includes)/:s', $file, $matches ) ) {
			$this->cached_file_locations[ $file ] = [
				'type' => 'core',
				'name' => $matches[1],
				'data' => null,
			];
			return $this->cached_file_locations[ $file ];
		}

		// Identify child theme file.
		if ( $this->current_theme->exists() && preg_match( ':' . preg_quote( $this->current_theme->get_stylesheet_directory(), ':' ) . '/:s', $file ) ) {
			$this->cached_file_locations[ $file ] = [
				'type' => 'theme',
				'name' => $this->current_theme->get_stylesheet(),
				'data' => $this->current_theme,
			];
			return $this->cached_file_locations[ $file ];
		}

		// Identify parent theme file.
		if ( $this->current_theme->parent() && preg_match( ':' . preg_quote( $this->current_theme->get_template_directory(), ':' ) . '/:s', $file ) ) {
			$this->cached_file_locations[ $file ] = [
				'type' => 'theme',
				'name' => $this->current_theme->get_template(),
				'data' => $this->current_theme->parent(),
			];
			return $this->cached_file_locations[ $file ];
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$plugin_dir     = null;
		$plugin_matches = null;
		foreach ( $this->plugins_directories as $plugin_directory ) {
			if ( preg_match( ':' . preg_quote( $plugin_directory, ':' ) . $slug_pattern . '(?P<rel_path>/.+$)?:s', $file, $matches ) ) {
				$plugin_matches = $matches;
				$plugin_dir     = $plugin_directory . '/' . $matches['root_slug'] . '/';
				break;
			}
			unset( $matches );
		}

		if ( $plugin_dir && $plugin_matches ) {
			// Fallback slug is the path segment under the plugins directory.
			$slug = $plugin_matches['root_slug'];

			$data = null;

			// Try getting the plugin data from the file itself if it is in a plugin directory.
			if ( empty( $plugin_matches['rel_path'] ) || 0 === substr_count( trim( $plugin_matches['rel_path'], '/' ), '/' ) ) {
				$data = get_plugin_data( $file );
			}

			// If the file is itself a plugin file, then the slug includes the rel_path under the root_slug.
			if ( ! empty( $data['Name'] ) && ! empty( $plugin_matches['rel_path'] ) ) {
				$slug .= $plugin_matches['rel_path'];
			}

			// If the file is not a plugin file, try looking for {slug}/{slug}.php.
			if ( empty( $data['Name'] ) && file_exists( $plugin_dir . $plugin_matches['root_slug'] . '.php' ) ) {
				$slug = $plugin_matches['root_slug'] . '/' . $plugin_matches['root_slug'] . '.php';
				$data = get_plugin_data( $plugin_dir . $plugin_matches['root_slug'] . '.php' );
			}

			// Otherwise, grab the first plugin file located in the plugin directory.
			if ( empty( $data['Name'] ) ) {
				$plugins = get_plugins( '/' . $plugin_matches['root_slug'] );
				if ( ! empty( $plugins ) ) {
					$key  = key( $plugins );
					$data = $plugins[ $key ];
					$slug = $plugin_matches['root_slug'] . '/' . $key;
				}
			}

			// Failed to locate the plugin.
			if ( empty( $data['Name'] ) ) {
				$data = null;
			}

			$this->cached_file_locations[ $file ] = [
				'type' => 'plugin',
				'name' => $slug,
				'data' => $data,
			];
			return $this->cached_file_locations[ $file ];
		}

		if ( preg_match( ':' . preg_quote( $this->mu_plugins_directory, ':' ) . $slug_pattern . ':s', $file, $matches ) ) {
			$this->cached_file_locations[ $file ] = [
				'type' => 'mu-plugin',
				'name' => $matches['root_slug'],
				'data' => get_plugin_data( $file ), // This is a best guess as $file may not actually be the plugin file.
			];
			return $this->cached_file_locations[ $file ];
		}

		return null;
	}
}
