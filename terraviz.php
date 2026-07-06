<?php
/**
 * Plugin Name:       Terraviz
 * Plugin URI:        https://github.com/zyra-project/terraviz-wordpress-plugin
 * Description:        Embed live Terraviz "Science On a Sphere" globes — a single dataset, a tour, or the full catalog — into WordPress pages and posts, with an indexable, accessible server-side fallback.
 * Version:           0.3.0
 * Requires at least: 6.1
 * Requires PHP:      7.4
 * Author:            Zyra Project
 * Author URI:        https://terraviz.zyra-project.org
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       terraviz
 * Domain Path:       /languages
 *
 * Terraviz — WordPress plugin: public globe embeds plus an optional,
 * credentialed publisher dashboard for managing Terraviz datasets.
 * Copyright (C) 2026 Zyra Project.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2, as
 * published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

namespace Terraviz;

// Abort if called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'TERRAVIZ_VERSION' ) ) {
	// Already loaded (e.g. plugin present in two locations); bail quietly.
	return;
}

/**
 * Plugin version. Kept in lock-step with the header above and readme.txt.
 */
define( 'TERRAVIZ_VERSION', '0.3.0' );

/**
 * Absolute path to this plugin's main file.
 */
define( 'TERRAVIZ_PLUGIN_FILE', __FILE__ );

/**
 * Absolute path to this plugin's directory, with a trailing slash.
 */
define( 'TERRAVIZ_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * URL to this plugin's directory, with a trailing slash.
 */
define( 'TERRAVIZ_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * The canonical Terraviz node the plugin points at out of the box.
 * Overridable site-wide from the settings screen and per-block.
 *
 * @see \Terraviz\Support\Options
 */
define( 'TERRAVIZ_DEFAULT_ORIGIN', 'https://terraviz.zyra-project.org' );

/**
 * The embed-URL grammar version this plugin composes against.
 *
 * @see https://github.com/zyra-project/terraviz — docs/EMBED_URL_GRAMMAR.md
 */
define( 'TERRAVIZ_EMBED_GRAMMAR', 'v1' );

/**
 * The wire-schema major version this plugin's Contract types were generated from.
 *
 * @see https://terraviz.zyra-project.org/schema/v1/
 */
define( 'TERRAVIZ_SCHEMA_VERSION', 'v1' );

/**
 * Lightweight PSR-4 autoloader for the Terraviz\ namespace.
 *
 * The plugin ships with no runtime Composer dependencies (Composer is
 * used only for dev tooling — PHPCS, PHPUnit), so we register our own
 * autoloader rather than requiring vendor/autoload.php. This keeps the
 * distributed plugin self-contained, per the "no phone-home, no bundled
 * externals" constraint.
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix   = 'Terraviz\\';
		$base_dir = TERRAVIZ_PLUGIN_DIR . 'src/';

		$len = strlen( $prefix );
		if ( 0 !== strncmp( $prefix, $class_name, $len ) ) {
			return;
		}

		$relative = substr( $class_name, $len );
		$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

// Boot the plugin once WordPress has loaded plugins.
Plugin::instance();

register_activation_hook( __FILE__, array( __NAMESPACE__ . '\\Plugin', 'on_activate' ) );
register_deactivation_hook( __FILE__, array( __NAMESPACE__ . '\\Plugin', 'on_deactivate' ) );
