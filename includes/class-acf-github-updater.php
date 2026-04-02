<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class ACF_GitHub_Updater {

    private $github_user    = 'pronamic';
    private $github_repo    = 'advanced-custom-fields-pro';
    private $acf_plugin     = 'advanced-custom-fields-pro/acf.php'; // slug di ACF Pro
    private $api_url        = 'https://api.github.com/repos/pronamic/advanced-custom-fields-pro/releases/latest';
    private $zip_base_url   = 'https://github.com/pronamic/advanced-custom-fields-pro/releases/download';
    private $transient_key  = 'acf_github_update_cache';
    private $cache_time     = 43200; // 12 ore

    public function __construct() {
        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_update' ] );
        add_filter( 'plugins_api', [ $this, 'plugin_info' ], 10, 3 );
        add_filter( 'upgrader_package_options', [ $this, 'fix_package_options' ] );
        add_action( 'upgrader_process_complete', [ $this, 'clear_cache' ], 10, 2 );
        add_action( 'admin_notices', [ $this, 'admin_notice' ] );
    }

    /**
     * Recupera l'ultima release da GitHub con cache transient
     */
    private function get_latest_release() {
        $cached = get_transient( $this->transient_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $response = wp_remote_get( $this->api_url, [
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ),
            ],
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['tag_name'] ) ) {
            return false;
        }

        $tag     = $body['tag_name'];
        $version = ltrim( $tag, 'v' );
        $zip_url = '';

        if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
            foreach ( $body['assets'] as $asset ) {
                if ( empty( $asset['name'] ) || empty( $asset['browser_download_url'] ) ) {
                    continue;
                }

                if ( $asset['name'] === 'advanced-custom-fields-pro.' . $version . '.zip' ) {
                    $zip_url = $asset['browser_download_url'];
                    break;
                }
            }
        }

        if ( empty( $zip_url ) ) {
            $zip_url = $this->zip_base_url . '/' . $tag . '/advanced-custom-fields-pro.' . $version . '.zip';
        }

        $release = [
            'version'     => $version,
            'tag'         => $tag,
            'zip_url'     => $zip_url,
            'description' => $body['body'] ?? '',
            'published'   => $body['published_at'] ?? '',
        ];

        set_transient( $this->transient_key, $release, $this->cache_time );

        return $release;
    }

    /**
     * Recupera la versione installata di ACF Pro
     */
    private function get_installed_version() {
        if ( ! function_exists( 'get_plugin_data' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugin_file = WP_PLUGIN_DIR . '/' . $this->acf_plugin;
        if ( ! file_exists( $plugin_file ) ) {
            return null;
        }
        $data = get_plugin_data( $plugin_file );
        return $data['Version'] ?? null;
    }

    /**
     * Inietta l'update nel transient nativo di WordPress
     */
    public function check_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $release  = $this->get_latest_release();
        $installed = $this->get_installed_version();

        if ( ! $release || ! $installed ) {
            return $transient;
        }

        if ( version_compare( $release['version'], $installed, '>' ) ) {
            error_log( 'ACF updater package: ' . $release['zip_url'] );
            $transient->response[ $this->acf_plugin ] = (object) [
                'slug'        => 'advanced-custom-fields-pro',
                'plugin'      => $this->acf_plugin,
                'new_version' => $release['version'],
                'url'         => 'https://github.com/' . $this->github_user . '/' . $this->github_repo,
                'package'     => $release['zip_url'],
                'icons'       => [],
                'banners'     => [],
                'tested'      => get_bloginfo('version'),
            ];
        }

        return $transient;
    }

    /**
     * Mostra info plugin nella modale "Visualizza dettagli versione"
     */
    public function plugin_info( $result, $action, $args ) {
        if ( $action !== 'plugin_information' ) {
            return $result;
        }
        if ( ! isset( $args->slug ) || $args->slug !== 'advanced-custom-fields-pro' ) {
            return $result;
        }

        $release = $this->get_latest_release();
        if ( ! $release ) {
            return $result;
        }

        return (object) [
            'name'          => 'Advanced Custom Fields PRO',
            'slug'          => 'advanced-custom-fields-pro',
            'version'       => $release['version'],
            'author'        => 'WP Engine',
            'homepage'      => 'https://github.com/' . $this->github_user . '/' . $this->github_repo,
            'download_link' => $release['zip_url'],
            'last_updated'  => $release['published'],
            'sections'      => [
                'description' => 'Aggiornamento tramite GitHub — ' . $this->github_user . '/' . $this->github_repo,
                'changelog'   => nl2br( esc_html( $release['description'] ) ),
            ],
        ];
    }

    /**
     * Assicura che dopo l'installazione la cartella si chiami
     * "advanced-custom-fields-pro" e non con il tag/versione
     */
    public function fix_package_options( $options ) {
        if (
            isset( $options['hook_extra']['plugin'] ) &&
            $options['hook_extra']['plugin'] === $this->acf_plugin
        ) {
            $options['destination'] = WP_PLUGIN_DIR . '/advanced-custom-fields-pro';
            $options['clear_destination'] = true;
        }
        return $options;
    }

    /**
     * Svuota la cache dopo un aggiornamento completato
     */
    public function clear_cache( $upgrader, $hook_extra ) {
        if (
            isset( $hook_extra['plugins'] ) &&
            in_array( $this->acf_plugin, $hook_extra['plugins'], true )
        ) {
            delete_transient( $this->transient_key );
        }
    }

    /**
     * Avviso admin se ACF Pro non è installato
     */
    public function admin_notice() {
        if ( ! file_exists( WP_PLUGIN_DIR . '/' . $this->acf_plugin ) ) {
            echo '<div class="notice notice-warning"><p>';
            echo '<strong>ACF GitHub Updater:</strong> Advanced Custom Fields PRO non trovato. ';
            echo 'Assicurati che sia installato in <code>advanced-custom-fields-pro/acf.php</code>.';
            echo '</p></div>';
        }
    }
}
