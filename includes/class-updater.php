<?php
/**
 * Self-updater: lets WordPress pull plugin updates from GitHub releases.
 *
 * The repository is public, so no authentication token is required. On each
 * update check WordPress runs, the latest GitHub release's tag (e.g. "v1.2.0")
 * is compared against the installed version; when the release is newer, the
 * standard "update available" notice appears and the plugin can update itself.
 *
 * Publishing a new version is just: bump the version header, tag a matching
 * GitHub release (v<version>), and sites will see the update within a few hours
 * (or immediately from Dashboard → Updates → "Check again").
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CPO_Updater {

	const GITHUB_OWNER = 'justin-netage';
	const GITHUB_REPO  = 'Custom-Portfolio-Ordering';
	const CACHE_KEY    = 'cpo_github_release';
	const CACHE_TTL    = 21600; // 6 hours.

	/** @var string Plugin basename, e.g. "custom-portfolio-ordering/custom-portfolio-ordering.php". */
	private $basename;

	/** @var string Installed plugin directory slug, e.g. "custom-portfolio-ordering". */
	private $slug;

	/** @var string Installed version. */
	private $version;

	/**
	 * @param string $plugin_file Absolute path to the main plugin file.
	 * @param string $version     Installed plugin version.
	 */
	public function __construct( $plugin_file, $version ) {
		$this->basename = plugin_basename( $plugin_file );
		$this->slug     = dirname( $this->basename );
		$this->version  = $version;

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ), 10, 2 );
	}

	/**
	 * Fetch the latest release from GitHub, cached to avoid API rate limits.
	 *
	 * @return object|null Decoded release object, or null on failure.
	 */
	private function get_latest_release() {
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			// An empty string is a cached "no release / failure" marker.
			return is_object( $cached ) ? $cached : null;
		}

		$url      = sprintf( 'https://api.github.com/repos/%s/%s/releases/latest', self::GITHUB_OWNER, self::GITHUB_REPO );
		$response = wp_remote_get( $url, array(
			'timeout' => 15,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'CPO-Updater/' . self::GITHUB_REPO,
			),
		) );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Cache the failure briefly so a bad response doesn't hammer the API.
			set_transient( self::CACHE_KEY, '', 10 * MINUTE_IN_SECONDS );
			return null;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ) );
		if ( ! is_object( $release ) || empty( $release->tag_name ) ) {
			set_transient( self::CACHE_KEY, '', 10 * MINUTE_IN_SECONDS );
			return null;
		}

		set_transient( self::CACHE_KEY, $release, self::CACHE_TTL );
		return $release;
	}

	/**
	 * Normalize a release tag ("v1.2.0") to a comparable version ("1.2.0").
	 *
	 * @param string $tag The release tag name.
	 * @return string
	 */
	private function tag_to_version( $tag ) {
		return ltrim( $tag, 'vV' );
	}

	/**
	 * Choose the best download URL for a release: a packaged .zip asset if the
	 * release provides one, otherwise GitHub's auto-generated source zipball.
	 *
	 * @param object $release The release object.
	 * @return string
	 */
	private function get_download_url( $release ) {
		if ( ! empty( $release->assets ) && is_array( $release->assets ) ) {
			foreach ( $release->assets as $asset ) {
				if ( ! empty( $asset->browser_download_url ) && preg_match( '/\.zip$/i', $asset->browser_download_url ) ) {
					return $asset->browser_download_url;
				}
			}
		}

		return ! empty( $release->zipball_url ) ? $release->zipball_url : '';
	}

	/**
	 * Inject update data into the plugins update transient when a newer
	 * GitHub release is available.
	 *
	 * @param mixed $transient The update_plugins transient.
	 * @return mixed
	 */
	public function check_for_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new stdClass();
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $transient;
		}

		$new_version = $this->tag_to_version( $release->tag_name );
		$download    = $this->get_download_url( $release );

		if ( ! $download || version_compare( $new_version, $this->version, '<=' ) ) {
			// No newer version — make sure we aren't falsely flagged.
			if ( isset( $transient->response[ $this->basename ] ) ) {
				unset( $transient->response[ $this->basename ] );
			}
			return $transient;
		}

		$transient->response[ $this->basename ] = (object) array(
			'id'          => 'github.com/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO,
			'slug'        => $this->slug,
			'plugin'      => $this->basename,
			'new_version' => $new_version,
			'url'         => sprintf( 'https://github.com/%s/%s', self::GITHUB_OWNER, self::GITHUB_REPO ),
			'package'     => $download,
		);

		return $transient;
	}

	/**
	 * Provide the "View version details" modal content for this plugin.
	 *
	 * @param mixed  $result The plugins_api result.
	 * @param string $action The requested action.
	 * @param object $args   The request arguments.
	 * @return mixed
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $result;
		}

		$info = array(
			'name'          => 'Custom Portfolio Ordering',
			'slug'          => $this->slug,
			'version'       => $this->tag_to_version( $release->tag_name ),
			'author'        => '<a href="https://github.com/' . self::GITHUB_OWNER . '">Justin Netage</a>',
			'homepage'      => sprintf( 'https://github.com/%s/%s', self::GITHUB_OWNER, self::GITHUB_REPO ),
			'download_link' => $this->get_download_url( $release ),
			'sections'      => array(
				'changelog' => $this->format_changelog( $release ),
			),
		);

		if ( ! empty( $release->published_at ) ) {
			$info['last_updated'] = $release->published_at;
		}

		return (object) $info;
	}

	/**
	 * Render release notes (light Markdown) as HTML for the details modal.
	 *
	 * @param object $release The release object.
	 * @return string
	 */
	private function format_changelog( $release ) {
		$body = ! empty( $release->body ) ? $release->body : 'No release notes provided.';

		$body = esc_html( $body );
		$body = preg_replace( '/^### (.*)$/m', '<h4>$1</h4>', $body );
		$body = preg_replace( '/^## (.*)$/m', '<h3>$1</h3>', $body );
		$body = preg_replace( '/^\s*[-*] (.*)$/m', '<li>$1</li>', $body );

		return nl2br( $body );
	}

	/**
	 * Rename the extracted source folder to the installed plugin slug so
	 * WordPress installs the update over the existing plugin. GitHub source
	 * zipballs extract to "owner-repo-<sha>/", which would otherwise create a
	 * new, wrongly-named plugin folder.
	 *
	 * @param string $source        The unpacked source directory.
	 * @param string $remote_source The unpacked working directory.
	 * @param object $upgrader      The WP_Upgrader instance.
	 * @param array  $hook_extra    Extra data about what is being upgraded.
	 * @return string|WP_Error
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;

		// Only touch our own plugin's upgrade.
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) {
			return $source;
		}

		if ( ! is_object( $wp_filesystem ) ) {
			return $source;
		}

		$desired = trailingslashit( trailingslashit( $remote_source ) . $this->slug );

		if ( trailingslashit( $source ) === $desired ) {
			return $source;
		}

		if ( $wp_filesystem->move( $source, $desired, true ) ) {
			return $desired;
		}

		return $source;
	}

	/**
	 * Clear the cached release after a plugin update completes so the next
	 * check reflects the freshly installed version.
	 *
	 * @param object $upgrader The WP_Upgrader instance.
	 * @param array  $data     Info about the completed process.
	 */
	public function clear_cache( $upgrader, $data ) {
		if ( isset( $data['action'], $data['type'] ) && 'update' === $data['action'] && 'plugin' === $data['type'] ) {
			delete_transient( self::CACHE_KEY );
		}
	}
}
