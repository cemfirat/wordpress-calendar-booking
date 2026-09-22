<?php
namespace Cemb\Updates;

if (!defined('ABSPATH')) {
    exit;
}

final class GitHubUpdater {
    public const REPOSITORY = 'https://github.com/cemfirat/wordpress-calendar-booking';
    public const API_URL = 'https://api.github.com/repos/cemfirat/wordpress-calendar-booking/releases/latest';
    public const CACHE_KEY = 'cemb_github_release_v1';
    public const SLUG = 'wordpress-calendar-booking';
    public const ASSET = 'wordpress-calendar-booking.zip';

    private string $basename;

    public function __construct(string $file) {
        $this->basename = plugin_basename($file);
        add_filter('update_plugins_github.com', [$this, 'checkUpdate'], 10, 3);
        add_filter('plugins_api', [$this, 'pluginInformation'], 10, 3);
        add_filter('upgrader_source_selection', [$this, 'preserveDirectory'], 10, 4);
        add_action('upgrader_process_complete', [$this, 'clearCacheAfterUpdate'], 10, 2);
        add_action('load-update-core.php', [$this, 'maybeForceCheck'], 1);
    }

    public function checkUpdate($update, array $pluginData, string $pluginFile) {
        if ($pluginFile !== $this->basename) {
            return $update;
        }

        $release = $this->getRelease();
        if (!$release) {
            return $update;
        }

        return [
            'id' => self::REPOSITORY,
            'slug' => self::SLUG,
            'version' => $release['version'],
            'url' => $release['url'],
            'package' => $release['package'],
            'requires' => $release['requires'],
            'requires_php' => $release['requires_php'],
        ];
    }

    public function maybeForceCheck(): void {
        if (current_user_can('update_plugins')
            && isset($_GET['force-check'])
            && '1' === sanitize_text_field(wp_unslash($_GET['force-check']))) {
            delete_site_transient(self::CACHE_KEY);
            delete_site_transient('update_plugins');
        }
    }

    public function getRelease() {
        $cached = get_site_transient(self::CACHE_KEY);
        if (false !== $cached) {
            return is_array($cached) && !empty($cached['version']) ? $cached : false;
        }

        $response = wp_remote_get(self::API_URL, [
            'timeout' => 8,
            'redirection' => 0,
            'limit_response_size' => 131072,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'WordPress-Calendar-Booking/' . CEMB_VERSION,
            ],
        ]);

        $release = false;
        if (!is_wp_error($response) && 200 === wp_remote_retrieve_response_code($response)) {
            $release = self::parseRelease(json_decode(wp_remote_retrieve_body($response), true));
        }

        set_site_transient(
            self::CACHE_KEY,
            $release ?: [],
            $release ? 6 * HOUR_IN_SECONDS : 15 * MINUTE_IN_SECONDS
        );

        return $release;
    }

    public static function parseRelease($data) {
        if (!is_array($data)
            || !isset($data['draft'], $data['prerelease'], $data['tag_name'], $data['body'], $data['assets'])
            || false !== $data['draft']
            || false !== $data['prerelease']
            || !is_string($data['tag_name'])
            || !is_string($data['body'])
            || !is_array($data['assets'])
            || !preg_match('/\Av([0-9]+\.[0-9]+\.[0-9]+)\z/', $data['tag_name'], $version)) {
            return false;
        }

        if (!preg_match('/^Requires WordPress: ([0-9]+\.[0-9]+(?:\.[0-9]+)?)\r?$/m', $data['body'], $wp)
            || !preg_match('/^Requires PHP: ([0-9]+\.[0-9]+(?:\.[0-9]+)?)\r?$/m', $data['body'], $php)) {
            return false;
        }

        $package = self::REPOSITORY . '/releases/download/' . $data['tag_name'] . '/' . self::ASSET;
        foreach ($data['assets'] as $asset) {
            if (is_array($asset)
                && isset($asset['name'], $asset['browser_download_url'], $asset['state'], $asset['size'])
                && self::ASSET === $asset['name']
                && $package === $asset['browser_download_url']
                && 'uploaded' === $asset['state']
                && is_numeric($asset['size'])
                && (int)$asset['size'] > 0) {
                return [
                    'version' => $version[1],
                    'url' => self::REPOSITORY . '/releases/tag/' . $data['tag_name'],
                    'package' => $package,
                    'requires' => $wp[1],
                    'requires_php' => $php[1],
                    'notes' => $data['body'],
                ];
            }
        }

        return false;
    }

    public function pluginInformation($result, string $action, $args) {
        if ('plugin_information' !== $action
            || !is_object($args)
            || !isset($args->slug)
            || self::SLUG !== $args->slug) {
            return $result;
        }

        $release = $this->getRelease();
        if (!$release) {
            return $result;
        }

        return (object)[
            'name' => 'WordPress Calendar Booking',
            'slug' => self::SLUG,
            'version' => $release['version'],
            'author' => 'Cem Firat',
            'homepage' => self::REPOSITORY,
            'requires' => $release['requires'],
            'requires_php' => $release['requires_php'],
            'download_link' => $release['package'],
            'sections' => [
                'description' => '<p>Privacy-conscious calendar availability and appointment booking for WordPress.</p>',
                'changelog' => '<pre>' . esc_html($release['notes']) . '</pre>',
            ],
        ];
    }

    public function preserveDirectory($source, $remoteSource, $upgrader, $hookExtra) {
        if (!is_array($hookExtra)
            || !isset($hookExtra['plugin'])
            || $hookExtra['plugin'] !== $this->basename) {
            return $source;
        }

        $directory = dirname($this->basename);
        if ('.' === $directory) {
            return new \WP_Error(
                'cemb_single_file_layout',
                'Install the release ZIP in its own plugin directory once before using automatic updates.'
            );
        }

        $destination = trailingslashit($remoteSource) . $directory . '/';
        if (untrailingslashit($source) === untrailingslashit($destination)) {
            return $source;
        }

        global $wp_filesystem;
        if (!$wp_filesystem
            || !$wp_filesystem->is_file(trailingslashit($source) . 'cemb-calendar-booking.php')
            || !$wp_filesystem->move($source, $destination)) {
            return new \WP_Error(
                'cemb_update_move_failed',
                'The update could not preserve the existing plugin directory. Check filesystem permissions and try again.'
            );
        }

        return $destination;
    }

    public function clearCacheAfterUpdate($upgrader, array $options): void {
        if (isset($options['type'], $options['action'])
            && 'plugin' === $options['type']
            && 'update' === $options['action']
            && ((isset($options['plugin']) && $options['plugin'] === $this->basename)
                || (isset($options['plugins']) && in_array($this->basename, $options['plugins'], true)))) {
            delete_site_transient(self::CACHE_KEY);
        }
    }
}
